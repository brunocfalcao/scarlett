<?php

declare(strict_types=1);

namespace Scarlett;

use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Scarlett\Definition\Subject;
use Scarlett\Definition\SubjectSlot;
use Scarlett\Definition\Workflow;
use Scarlett\Execution\ChildPhase;
use Scarlett\Execution\Completion;
use Scarlett\Execution\Decision;
use Scarlett\Execution\Delivery;
use Scarlett\Execution\FailureCause;
use Scarlett\Execution\Journal;
use Scarlett\Execution\NodeStore;
use Scarlett\Execution\Progression;
use Scarlett\Execution\RuntimeChildren;
use Scarlett\Failures\Coordinator;
use Scarlett\Inputs\PayloadCodec;
use Scarlett\Models\StepRecord;
use Scarlett\Models\WorkflowRecord;
use Scarlett\States\Cancelled;
use Scarlett\States\Failed;
use Scarlett\States\Phase;
use Scarlett\States\Running;
use Scarlett\States\WaitingForChildren;

/**
 * Coordinates durable workflow changes; step actions run outside mutation locks.
 * Guarded callbacks run inside those locks; attempt mutations recheck ownership.
 */
final class Engine
{
    public function __construct(
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

    /** Wrap before whileCurrent or consumer locks when their transaction will admit new work. */
    public function withAdmission(callable $operation): mixed
    {
        return app(Admission::class)->transaction($operation);
    }

    /** Call before consumer locks, or inside an outer withAdmission transaction acquired first. */
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
            app(NodeStore::class)->store($workflow->id, $definition);
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
            $step = $workflow->steps()->whereKey($stepId)->lockForUpdate()->first();
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

    /** Advisory read only; use whileCurrent to make ownership and database writes atomic. */
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

    /** Save the result before verification so its retries cannot repeat the business action. */
    public function saveAction(string $stepId, string $attempt, mixed $result, string $completionState): bool
    {
        $payload = $this->codec->encode($result);

        return (bool) $this->mutate($stepId, function (StepRecord $step) use ($attempt, $payload, $completionState): bool {
            if (! $this->owns($step, $attempt)) {
                return false;
            }
            $from = $step->workflow->steps()->where('parent_key', $step->key)->lockForUpdate()->first() !== null ? WaitingForChildren::class : Running::class;
            app(Completion::class)->validate($completionState, $from);
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
            $hasChildren = $step->child_phase !== ChildPhase::Verifying
                && $step->workflow->steps()->where('parent_key', $step->key)->lockForUpdate()->first() !== null;
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
            if ($decision->children !== null) {
                if ($verification || $step->action_completed) {
                    throw new \InvalidArgumentException('Children can only be handed off before the action is persisted.');
                }
                app(RuntimeChildren::class)->admit($step, $decision);

                return true;
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
            foreach ($workflow->steps()->lockForUpdate()->get() as $step) {
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
                $delivery = $this->delivery->replayable($step)->lockForUpdate()->first();
                if ($delivery === null) {
                    return false;
                }
                // MySQL reports zero changed rows when an unsent intent already has these values.
                // Eligibility, not the update count, determines whether to publish after commit.
                DB::table('scarlett_deliveries')->where('id', $delivery->id)->update(['sent_at' => null, 'updated_at' => now()]);

                return true;
            }
            if ($step->state::phase() !== Phase::Running || $step->lease_until === null || $step->lease_until->isFuture()) {
                return false;
            }
            $step->error = 'Execution lease expired.';
            $this->retry($step, $step->action_completed, cause: FailureCause::AttemptLost);

            return true;
        });
    }

    /** Action and verification counters are independent; throttles never enter this path. */
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
