<?php

declare(strict_types=1);

namespace Scarlett;

use Closure;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Scarlett\Definition\Subject;
use Scarlett\Definition\SubjectSlot;
use Scarlett\Definition\Workflow;
use Scarlett\Execution\Decision;
use Scarlett\Execution\Delivery;
use Scarlett\Execution\FailureCause;
use Scarlett\Execution\Journal;
use Scarlett\Execution\Progression;
use Scarlett\Failures\Coordinator;
use Scarlett\Inputs\InputBindings;
use Scarlett\Inputs\PayloadCodec;
use Scarlett\Models\StepRecord;
use Scarlett\Models\WorkflowRecord;
use Scarlett\States\Cancelled;
use Scarlett\States\Failed;
use Scarlett\States\Pending;
use Scarlett\States\Phase;
use Scarlett\States\Running;
use Scarlett\States\StateRegistry;
use Scarlett\States\WaitingForChildren;

final class Engine
{
    public function __construct(
        private InputBindings $bindings,
        private PayloadCodec $codec,
        private Progression $progression,
        private Journal $journal,
        private Delivery $delivery,
    ) {}

    public function replace(SubjectSlot $slot, callable $prepare): ?WorkflowRecord
    {
        return app(WorkflowSlots::class)->replace($slot, $prepare);
    }

    public function current(SubjectSlot $slot): ?string
    {
        return app(WorkflowSlots::class)->current($slot);
    }

    /**
     * @param  array<array-key, string|int>  $subjectIds
     * @return array<string|int, string>
     */
    public function currentForSubjects(string $subjectType, array $subjectIds, string $slotName): array
    {
        return app(WorkflowSlots::class)->currentForSubjects($subjectType, $subjectIds, $slotName);
    }

    /** @param list<Subject> $guardSubjects */
    public function deleteSubject(Subject $subject, callable $prepare, array $guardSubjects = []): bool
    {
        return app(SubjectDeletion::class)->delete($subject, $prepare, $guardSubjects);
    }

    public function start(Workflow $definition): WorkflowRecord
    {
        app(Configuration::class)->validate();
        $definition->validate();

        return DB::transaction(function () use ($definition): WorkflowRecord {
            $control = DB::table('scarlett_control')->where('id', 'admission')->sharedLock()->first();
            $workflow = WorkflowRecord::create([
                'id' => (string) Str::uuid(),
                'subject_type' => $definition->subjectType,
                'subject_id' => $definition->subjectId,
                'metadata' => $definition->metadata,
                'status' => $control->cooldown ? 'held' : 'running',
                'started_at' => $control->cooldown ? null : now(),
            ]);
            foreach ($definition->nodes as $node) {
                $dependencies = $definition->dependencies($node);
                StepRecord::create([
                    'id' => (string) Str::uuid(),
                    'workflow_id' => $workflow->id,
                    'key' => $node->key,
                    'class' => $node->step,
                    'state' => Pending::class,
                    'dependencies' => $dependencies,
                    'inputs' => $this->bindings->encode($node->inputs, array_merge($dependencies, array_filter([$node->parent, $node->rescues], fn (?string $key): bool => $key !== null))),
                    'parent_key' => $node->parent,
                    'rescues_key' => $node->rescues,
                    'execution' => $node->executionOptions()->snapshot(),
                    'not_before' => $node->notBefore === null ? null : Carbon::instance($node->notBefore)->setTimezone(config('app.timezone')),
                    'priority' => $node->priority,
                    'queue' => $node->queue ?? config('scarlett.queue'),
                    'connection' => $node->connection ?? config('scarlett.connection'),
                ]);
            }
            $this->progression->advance($workflow);
            $this->delivery->afterCommit($workflow->id);

            return $workflow;
        }, (int) config('scarlett.transaction_attempts'));
    }

    /** A workflow lock serializes claims, outcomes, cancellation, recovery and admission. */
    private function mutate(string $stepId, Closure $operation, bool $advance = true): mixed
    {
        $workflowId = StepRecord::whereKey($stepId)->toBase()->value('workflow_id');
        if ($workflowId === null) {
            return null;
        }

        return DB::transaction(function () use ($stepId, $workflowId, $operation, $advance): mixed {
            $workflow = WorkflowRecord::whereKey($workflowId)->lockForUpdate()->first();
            if (! $workflow) {
                return null;
            }
            $step = $workflow->steps()->whereKey($stepId)->first();
            if (! $step) {
                return null;
            }
            $result = $operation($step);
            if ($advance && $result !== null && $result !== false) {
                $this->progression->advance($workflow);
                $this->delivery->afterCommit($workflowId);
            }

            return $result;
        }, (int) config('scarlett.transaction_attempts'));
    }

    public function claim(string $stepId, string $attempt): ?StepRecord
    {
        return $this->mutate($stepId, function (StepRecord $step) use ($attempt): ?StepRecord {
            if ($step->attempt !== $attempt || $step->state::phase() !== Phase::Queued || $step->available_at->isFuture()) {
                return null;
            }
            $step->lease_until = now()->addSeconds($step->executionOptions()['lease_seconds']);
            $step->save();
            $this->journal->transition($step, Running::class);

            return $step;
        }, advance: false);
    }

    public function isCurrent(string $stepId, string $attempt): bool
    {
        $step = StepRecord::find($stepId);

        return $step !== null && $this->owns($step, $attempt);
    }

    private function owns(StepRecord $step, string $attempt): bool
    {
        return $step->attempt === $attempt && $step->state::phase() === Phase::Running && $step->lease_until?->isFuture();
    }

    public function heartbeat(string $stepId, string $attempt): bool
    {
        return (bool) $this->mutate($stepId, function (StepRecord $step) use ($attempt): bool {
            if (! $this->owns($step, $attempt)) {
                return false;
            }
            $step->update(['lease_until' => now()->addSeconds($step->executionOptions()['lease_seconds'])]);

            return true;
        }, advance: false);
    }

    public function saveAction(string $stepId, string $attempt, mixed $result, string $completionState): bool
    {
        $payload = $this->codec->encode($result);

        return (bool) $this->mutate($stepId, function (StepRecord $step) use ($attempt, $payload, $completionState): bool {
            if (! $this->owns($step, $attempt)) {
                return false;
            }
            if (! in_array($completionState, app(StateRegistry::class)->classes(), true) || ! $completionState::satisfiesDependency() || $completionState::skipsChildren()) {
                throw new \InvalidArgumentException('An acceptable result requires a registered successful state.');
            }
            $from = $step->workflow->steps()->where('parent_key', $step->key)->exists() ? WaitingForChildren::class : Running::class;
            $reachable = collect(app(StateRegistry::class)->transitions())
                ->contains(fn (array $transition): bool => $transition[0] === $from && $transition[1] === $completionState);
            if (! $reachable) {
                throw new \InvalidArgumentException("Register the {$from} -> {$completionState} transition in scarlett.transitions.");
            }
            $step->update(['result' => $payload, 'has_result' => true, 'action_completed' => true, 'completion_state' => $completionState]);

            return true;
        }, advance: false);
    }

    public function finish(string $stepId, string $attempt): bool
    {
        return (bool) $this->mutate($stepId, function (StepRecord $step) use ($attempt): bool {
            if (! $this->owns($step, $attempt) || ! $step->action_completed) {
                return false;
            }
            $hasChildren = $step->workflow->steps()->where('parent_key', $step->key)->exists();
            $step->update(['lease_until' => null]);
            $this->journal->conclude($step, $hasChildren ? WaitingForChildren::class : $step->completion_state);

            return true;
        });
    }

    public function decide(string $stepId, string $attempt, Decision $decision, ?string $error = null, bool $verification = false, FailureCause $cause = FailureCause::Decided): bool
    {
        return (bool) $this->mutate($stepId, function (StepRecord $step) use ($attempt, $decision, $error, $verification, $cause): bool {
            if (! $this->owns($step, $attempt)) {
                return false;
            }
            $error = $decision->reason ?? $error;
            $step->error = $error;
            if ($decision->state !== null) {
                if ($decision->producesResult) {
                    throw new \InvalidArgumentException('Successful decisions must pass action persistence and verification.');
                }
                $step->lease_until = null;
                $step->save();
                $this->journal->transition($step, $decision->state, $error, $cause);

                return true;
            }
            if ($decision->throttled) {
                $step->save();
                $delay = $decision->delay === 0 ? config('scarlett.minimum_throttle_seconds') : $decision->delay;
                $this->progression->enqueue($step, $delay);
            } else {
                $this->retry($step, $verification, $decision->delay);
            }

            return true;
        });
    }

    public function cancel(string $stepId, bool $includingRunning = false): bool
    {
        return (bool) $this->mutate($stepId, function (StepRecord $step) use ($includingRunning): bool {
            $phase = $step->state::phase();
            if ($phase === Phase::Settled || (! $includingRunning && ! in_array($phase, [Phase::Pending, Phase::Queued], true))) {
                return false;
            }
            $this->journal->transition($step, Cancelled::class, 'Execution authority withdrawn.');

            return true;
        });
    }

    /** Revokes all unfinished attempts atomically. It cannot interrupt an external call already in progress. */
    public function cancelWorkflow(string $workflowId): bool
    {
        return DB::transaction(function () use ($workflowId): bool {
            $workflow = WorkflowRecord::whereKey($workflowId)->lockForUpdate()->first();
            if ($workflow === null || $workflow->finished_at !== null) {
                return false;
            }
            foreach ($workflow->steps()->get() as $step) {
                if ($step->state::phase() !== Phase::Settled) {
                    $this->journal->transition($step, Cancelled::class, 'Workflow execution authority withdrawn.');
                }
            }
            app(Coordinator::class)->cancel($workflow);
            $this->progression->advance($workflow);

            return true;
        }, (int) config('scarlett.transaction_attempts'));
    }

    /**
     * Execute database writes under the same lock as cancellation and recovery. Acquire consumer locks
     * inside this callback, consistently engine first. Never put network calls inside the transaction.
     */
    public function whileCurrent(string $stepId, string $attempt, callable $write): bool
    {
        return (bool) $this->mutate($stepId, function (StepRecord $step) use ($attempt, $write): bool {
            if (! $this->owns($step, $attempt)) {
                return false;
            }
            $write();

            return true;
        }, advance: false);
    }

    public function recover(string $stepId): bool
    {
        return (bool) $this->mutate($stepId, function (StepRecord $step): bool {
            if ($step->state::phase() === Phase::Queued && ! $step->available_at->isFuture()) {
                return $this->delivery->replayable($step)->update(['sent_at' => null, 'updated_at' => now()]) > 0;
            }
            if ($step->state::phase() !== Phase::Running || $step->lease_until === null || $step->lease_until->isFuture()) {
                return false;
            }
            $step->error = 'Execution lease expired.';
            $this->retry($step, $step->action_completed, cause: FailureCause::AttemptLost);

            return true;
        });
    }

    private function retry(StepRecord $step, bool $verification, ?int $delay = null, FailureCause $cause = FailureCause::BudgetExhausted): void
    {
        $counter = $verification ? 'verifications' : 'failures';
        $failures = $step->nextFailureCount($verification);
        $step->{$counter} = $failures;
        $step->save();
        $policy = app(config('scarlett.retry_policy'));
        if ($policy->permits($failures, $verification, $step)) {
            $this->progression->enqueue($step, $delay ?? $policy->delay($failures, $verification, $step));
        } else {
            $step->update(['lease_until' => null]);
            $this->journal->transition($step, Failed::class, $step->error ?? 'Retry budget exhausted.', $cause);
        }
    }

    public function stalled(?string $workflowId = null): bool
    {
        return app(Recovery::class)->stalled($workflowId);
    }

    public function resume(?string $workflowId = null): int
    {
        return app(Recovery::class)->resume($workflowId);
    }

    public function cooldown(): void
    {
        app(Admission::class)->cooldown();
    }

    public function reopen(): void
    {
        app(Admission::class)->reopen();
    }
}
