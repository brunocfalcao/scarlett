<?php

declare(strict_types=1);

/**
 * Manages durable follow-up work after a step has permanently failed or stopped.
 *
 * A failure hook is separate from onException(): it runs after ordinary retries are
 * over, has its own queued attempt token and lease, and can itself retry. Keep one
 * logical failure row per opted-in step, preserving the original reason and inputs.
 * Parent hooks wait for ordinary child steps and their hooks before running.
 * All changes share the same workflow-first locks as ordinary action execution.
 */

namespace Scarlett\Failures;

use Closure;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use ReflectionMethod;
use Scarlett\Execution\Delivery as WorkflowDelivery;
use Scarlett\Execution\FailureCause;
use Scarlett\Execution\Progression;
use Scarlett\Execution\WorkflowAttemptLock;
use Scarlett\Models\FailureRecord;
use Scarlett\Models\StepRecord;
use Scarlett\Models\WorkflowRecord;
use Scarlett\States\Phase;
use Scarlett\Step;

/**
 * Owns one durable failure record per opted-in failed step and its recoverable attempts.
 * Parent hooks await ordinary descendants and their hooks, not an external exactly-once guarantee.
 */
final class Coordinator
{
    public function __construct(private WorkflowAttemptLock $attemptLock = new WorkflowAttemptLock) {}

    /** Called by the journal inside the owning workflow transaction. */
    public function register(StepRecord $step, FailureCause $cause, ?string $reason): void
    {
        // Missing deployed classes still need a durable diagnostic instead of blocking settlement.
        $handlesFailure = ! is_subclass_of($step->class, Step::class)
            || collect(['onFailure', 'onFailureWithoutInstance'])->contains(
                fn (string $method): bool => (new ReflectionMethod($step->class, $method))->getDeclaringClass()->getName() !== Step::class,
            );
        if (! $handlesFailure) {
            return;
        }
        $workflow = $step->workflow;
        /**
         * A step has one logical terminal failure, even if a transition path is revisited.
         * Save input recipes and subject metadata so a fallback hook can still inspect the
         * failure if live input restoration or step construction later becomes impossible.
         */
        FailureRecord::firstOrCreate(['step_id' => $step->id], [
            'id' => (string) Str::uuid(),
            'workflow_id' => $step->workflow_id,
            'cause' => $cause,
            'reason' => $reason,
            'status' => Status::Pending,
            'snapshot' => [
                'key' => $step->key,
                'class' => $step->class,
                'inputs' => $step->inputs,
                'subject_type' => $workflow->subject_type,
                'subject_id' => $workflow->subject_id,
                'metadata' => $workflow->metadata,
            ],
        ]);
    }

    /** Children may fail later while running; wait for the entire ordinary subtree before the parent's hook. */
    public function advance(WorkflowRecord $workflow, Collection $steps): void
    {
        // Use current hook outcomes even inside a consumer's older transaction snapshot.
        $failures = $workflow->failures()->lockForUpdate()->get()->keyBy('step_id');
        $stepsById = $steps->keyBy('id');
        $childrenByParent = $steps->groupBy('parent_key');
        foreach ($failures as $failure) {
            if ($failure->status !== Status::Pending) {
                continue;
            }
            $owner = $stepsById->get($failure->step_id);
            if ($owner === null) {
                throw new \RuntimeException("Failure {$failure->id} has no owner step.");
            }
            /**
             * Do not notify the parent while an ordinary child can still fail or its hook is
             * unfinished. This traversal follows parent links only; rescue-branch progression
             * has its own ordering and is not added to this terminal-hook dependency tree.
             */
            $descendants = $this->descendants($childrenByParent, $owner->key);
            $waiting = $descendants->contains(fn (StepRecord $step): bool => $step->state::phase() !== Phase::Settled
                || (isset($failures[$step->id]) && ! $failures[$step->id]->status->settled()));
            if (! $waiting) {
                $this->enqueue($failure);
            }
        }
    }

    private function descendants(Collection $childrenByParent, string $parent): Collection
    {
        $result = collect();
        /**
         * Use an explicit stack to visit ordinary descendants at any depth. The prebuilt
         * parent index avoids repeatedly scanning the full workflow for each child's group.
         */
        $stack = $childrenByParent->get($parent, collect())->all();
        while ($stack !== []) {
            $child = array_pop($stack);
            $result->push($child);
            foreach ($childrenByParent->get($child->key, collect())->all() as $grandchild) {
                $stack[] = $grandchild;
            }
        }

        return $result;
    }

    private function enqueue(FailureRecord $failure, int $delay = 0): void
    {
        $failure->update([
            'status' => Status::Queued,
            'attempt' => (string) Str::uuid(),
            'available_at' => now()->addSeconds($delay),
            'lease_until' => null,
            'sent_at' => null,
            'publishing_until' => null,
        ]);
    }

    /** The workflow lock is shared with step execution, replacement, cancellation and history cleanup. */
    private function mutate(string $id, Closure $operation, bool $advance = true): mixed
    {
        return $this->attemptLock->mutate(
            $id,
            fn (string $failureId): mixed => FailureRecord::whereKey($failureId)->toBase()->value('workflow_id'),
            fn (WorkflowRecord $workflow, string $failureId): ?FailureRecord => $workflow->failures()->whereKey($failureId)->lockForUpdate()->first(),
            $operation,
            $advance ? function (WorkflowRecord $workflow, string $workflowId): void {
                app(Progression::class)->advance($workflow);
                app(WorkflowDelivery::class)->afterCommit($workflowId);
            } : null,
        );
    }

    public function claim(string $id, string $attempt): ?FailureRecord
    {
        return $this->mutate($id, function (FailureRecord $failure) use ($attempt): ?FailureRecord {
            if ($failure->attempt !== $attempt || $failure->status !== Status::Queued || $failure->available_at->isFuture()) {
                return null;
            }
            $failure->update([
                'status' => Status::Running,
                'lease_until' => now()->addSeconds($failure->step->executionOptions()['lease_seconds']),
            ]);

            return $failure;
        }, advance: false);
    }

    private function owns(FailureRecord $failure, string $attempt): bool
    {
        return $failure->attempt === $attempt && $failure->status === Status::Running && $failure->lease_until?->isFuture();
    }

    public function isCurrent(string $id, string $attempt): bool
    {
        $failure = FailureRecord::find($id);

        return $failure !== null && $this->owns($failure, $attempt);
    }

    public function whileCurrent(string $id, string $attempt, callable $write): bool
    {
        return (bool) $this->mutate($id, function (FailureRecord $failure) use ($attempt, $write): bool {
            if (! $this->owns($failure, $attempt)) {
                return false;
            }
            $write();

            return true;
        }, advance: false);
    }

    public function heartbeat(string $id, string $attempt): bool
    {
        return (bool) $this->mutate($id, function (FailureRecord $failure) use ($attempt): bool {
            if (! $this->owns($failure, $attempt)) {
                return false;
            }
            $failure->update(['lease_until' => now()->addSeconds($failure->step->executionOptions()['lease_seconds'])]);

            return true;
        }, advance: false);
    }

    public function canRetry(string $id, string $attempt): bool
    {
        $failure = FailureRecord::find($id);

        return $failure !== null && $this->owns($failure, $attempt)
            && app(config('scarlett.retry_policy'))->permits($failure->failures + 1, false, $failure->step);
    }

    public function complete(string $id, string $attempt): bool
    {
        return (bool) $this->mutate($id, function (FailureRecord $failure) use ($attempt): bool {
            if (! $this->owns($failure, $attempt)) {
                return false;
            }
            $failure->update(['status' => Status::Completed, 'lease_until' => null, 'finished_at' => now()]);

            return true;
        });
    }

    public function reject(string $id, string $attempt, string $error): bool
    {
        return (bool) $this->mutate($id, function (FailureRecord $failure) use ($attempt, $error): bool {
            if (! $this->owns($failure, $attempt)) {
                return false;
            }
            $this->retry($failure, $error);

            return true;
        });
    }

    private function retry(FailureRecord $failure, string $error): void
    {
        /**
         * A failure-hook error spends the hook's own failure count using the step's saved
         * retry settings. It does not change the original action's result or failure cause.
         * Exhaustion settles the hook so the workflow is not held open indefinitely.
         */
        $failure->failures++;
        $failure->error = $error;
        $failure->save();
        $policy = app(config('scarlett.retry_policy'));
        if ($policy->permits($failure->failures, false, $failure->step)) {
            $this->enqueue($failure, $policy->delay($failure->failures, false, $failure->step));
        } else {
            $failure->update(['status' => Status::Failed, 'lease_until' => null, 'finished_at' => now()]);
        }
    }

    public function cancel(WorkflowRecord $workflow): void
    {
        $workflow->failures()->unfinished()->update([
            'status' => Status::Cancelled->value,
            'lease_until' => null,
            'finished_at' => now(),
        ]);
    }

    public function needsRecovery(FailureRecord $failure): bool
    {
        if ($failure->status === Status::Running) {
            return $failure->lease_until !== null && ! $failure->lease_until->isFuture();
        }

        return $failure->status === Status::Queued && ! $failure->available_at->isFuture()
            && ($failure->publishing_until === null || ! $failure->publishing_until->isFuture())
            && ($failure->sent_at === null || $failure->sent_at->lte(now()->subSeconds((int) config('scarlett.delivery_retry_seconds'))));
    }

    public function recover(string $id): bool
    {
        return (bool) $this->mutate($id, function (FailureRecord $failure): bool {
            if (! $this->needsRecovery($failure)) {
                return false;
            }
            if ($failure->status === Status::Running) {
                $this->retry($failure, 'Failure execution lease expired.');
            } else {
                $failure->update(['sent_at' => null]);
            }

            return true;
        });
    }
}
