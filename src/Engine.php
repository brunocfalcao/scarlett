<?php

declare(strict_types=1);

namespace Scarlett;

use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Scarlett\Definition\Workflow;
use Scarlett\Execution\Decision;
use Scarlett\Execution\Delivery;
use Scarlett\Execution\Journal;
use Scarlett\Execution\Progression;
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

    public function start(Workflow $definition): WorkflowRecord
    {
        app(Configuration::class)->validate();
        $definition->validate();

        return DB::transaction(function () use ($definition): WorkflowRecord {
            $control = DB::table('scarlett_control')->where('id', 'admission')->lockForUpdate()->first();
            $workflow = WorkflowRecord::create([
                'id' => (string) Str::uuid(),
                'status' => $control->cooldown ? 'held' : 'running',
                'started_at' => $control->cooldown ? null : now(),
            ]);
            foreach ($definition->nodes as $node) {
                $dependencies = $definition->dependencies($node);
                StepRecord::create(['id' => (string) Str::uuid(), 'workflow_id' => $workflow->id, 'key' => $node->key,
                    'class' => $node->step, 'state' => Pending::class, 'dependencies' => $dependencies,
                    'inputs' => $this->bindings->encode($node->inputs, array_merge($dependencies, array_filter([$node->parent, $node->rescues]))),
                    'parent_key' => $node->parent, 'rescues_key' => $node->rescues,
                    'priority' => $node->priority, 'queue' => $node->queue ?? config('scarlett.queue'),
                    'connection' => $node->connection ?? config('scarlett.connection')]);
            }
            $this->progression->advance($workflow);
            DB::afterCommit(fn () => $this->delivery->flush($workflow->id));

            return $workflow;
        }, (int) config('scarlett.transaction_attempts'));
    }

    /** A workflow lock serializes claims, outcomes, cancellation, recovery and admission. */
    private function mutate(string $stepId, Closure $operation): mixed
    {
        $workflowId = StepRecord::whereKey($stepId)->value('workflow_id');
        if ($workflowId === null) {
            return null;
        }

        return DB::transaction(function () use ($stepId, $workflowId, $operation): mixed {
            $workflow = WorkflowRecord::whereKey($workflowId)->lockForUpdate()->first();
            if (! $workflow) {
                return null;
            }
            $step = $workflow->steps()->whereKey($stepId)->first();
            if (! $step) {
                return null;
            }
            $result = $operation($step);
            $this->progression->advance($workflow);
            DB::afterCommit(fn () => $this->delivery->flush($workflowId));

            return $result;
        }, (int) config('scarlett.transaction_attempts'));
    }

    public function claim(string $stepId, string $attempt): ?StepRecord
    {
        return $this->mutate($stepId, function (StepRecord $step) use ($attempt): ?StepRecord {
            if ($step->attempt !== $attempt || $step->state::phase() !== Phase::Queued || $step->available_at->isFuture()) {
                return null;
            }
            $step->lease_until = now()->addSeconds((int) config('scarlett.lease_seconds'));
            $step->save();
            $this->journal->transition($step, Running::class);

            return $step;
        });
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
            $step->update(['lease_until' => now()->addSeconds((int) config('scarlett.lease_seconds'))]);

            return true;
        });
    }

    public function saveAction(string $stepId, string $attempt, mixed $result, string $completionState): bool
    {
        $payload = $this->codec->encode($result);

        return (bool) $this->mutate($stepId, function (StepRecord $step) use ($attempt, $payload, $completionState): bool {
            if (! $this->owns($step, $attempt)) {
                return false;
            }
            if (! in_array($completionState, app(StateRegistry::class)->classes(), true) || ! $completionState::satisfiesDependency()) {
                throw new \InvalidArgumentException('An acceptable result requires a registered successful state.');
            }
            $step->update(['result' => $payload, 'has_result' => true, 'action_completed' => true, 'completion_state' => $completionState]);

            return true;
        });
    }

    public function finish(string $stepId, string $attempt): bool
    {
        return (bool) $this->mutate($stepId, function (StepRecord $step) use ($attempt): bool {
            if (! $this->owns($step, $attempt) || ! $step->action_completed) {
                return false;
            }
            $hasChildren = $step->workflow->steps()->where('parent_key', $step->key)->exists();
            $step->update(['lease_until' => null]);
            $this->journal->transition($step, $hasChildren ? WaitingForChildren::class : $step->completion_state);

            return true;
        });
    }

    public function decide(string $stepId, string $attempt, Decision $decision, ?string $error = null, bool $verification = false): bool
    {
        return (bool) $this->mutate($stepId, function (StepRecord $step) use ($attempt, $decision, $error, $verification): bool {
            if (! $this->owns($step, $attempt)) {
                return false;
            }
            $step->error = $error;
            if ($decision->state !== null) {
                if ($decision->producesResult) {
                    throw new \InvalidArgumentException('Successful decisions must pass action persistence and verification.');
                }
                $step->lease_until = null;
                $step->save();
                $this->journal->transition($step, $decision->state, $error);

                return true;
            }
            if (! $decision->throttled) {
                $counter = $verification ? 'verifications' : 'failures';
                $step->{$counter}++;
            }
            $step->save();
            $policy = app(config('scarlett.retry_policy'));
            $failures = $verification ? $step->verifications : $step->failures;
            if (! $decision->throttled && ! $policy->permits($failures, $verification)) {
                $step->update(['lease_until' => null]);
                $this->journal->transition($step, Failed::class, $error ?? 'Retry budget exhausted.');
            } else {
                $this->progression->enqueue($step, $decision->delay ?? $policy->delay($failures, $verification));
            }

            return true;
        });
    }

    public function cancel(string $stepId): bool
    {
        return (bool) $this->mutate($stepId, function (StepRecord $step): bool {
            if (! in_array($step->state::phase(), [Phase::Pending, Phase::Queued], true)) {
                return false;
            }
            $this->journal->transition($step, Cancelled::class, 'Cancelled before execution.');

            return true;
        });
    }

    public function recover(string $stepId): bool
    {
        return (bool) $this->mutate($stepId, function (StepRecord $step): bool {
            if ($step->state::phase() === Phase::Queued && ! $step->available_at->isFuture()) {
                return DB::table('scarlett_deliveries')->where('step_id', $step->id)->where('attempt', $step->attempt)
                    ->where(fn ($query) => $query->whereNull('publishing_until')->orWhere('publishing_until', '<=', now()))
                    ->where(function ($query): void {
                        $query->whereNull('sent_at')->orWhere('sent_at', '<=', now()->subSeconds((int) config('scarlett.delivery_retry_seconds')));
                    })->update(['sent_at' => null, 'updated_at' => now()]) > 0;
            }
            if ($step->state::phase() !== Phase::Running || $step->lease_until === null || $step->lease_until->isFuture()) {
                return false;
            }
            $verification = $step->action_completed;
            $counter = $verification ? 'verifications' : 'failures';
            $step->{$counter}++;
            $step->error = 'Execution lease expired.';
            $step->save();
            $policy = app(config('scarlett.retry_policy'));
            if ($policy->permits($step->{$counter}, $verification)) {
                $this->progression->enqueue($step, $policy->delay($step->{$counter}, $verification));
            } else {
                $step->update(['lease_until' => null]);
                $this->journal->transition($step, Failed::class, $step->error);
            }

            return true;
        });
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
