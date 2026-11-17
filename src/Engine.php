<?php

declare(strict_types=1);

/**
 * The public entry point for changing a workflow's saved execution state.
 *
 * A workflow is the complete group of steps. An attempt is one delivery of one step,
 * identified by a new token each time it is queued. A lease is the deadline until
 * which that attempt may still save results. Matching the token alone is not enough:
 * cancellation or an expired lease must also prevent an old worker from writing.
 *
 * This class changes records under database locks. Runner calls business methods
 * outside those locks, then comes back here to save their outcomes. Consequently,
 * an external action must tolerate repetition if a worker dies before saving it.
 */

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
use Scarlett\Execution\WorkflowAttemptLock;
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
        private WorkflowAttemptLock $attemptLock = new WorkflowAttemptLock,
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
        /**
         * Check settings and the entire step declaration before creating records. Invalid
         * inputs should fail at the request boundary, before a worker receives any job.
         */
        app(Configuration::class)->validate();
        $definition->validate();

        return DB::transaction(function () use ($definition): WorkflowRecord {
            /**
             * Admission is the gate shared with replacement and deletion operations. Take it
             * first so those operations cannot interleave with partially registered work.
             * Cooldown holds new workflows; it does not stop already started workflows.
             */
            $control = app(Admission::class)->lockShared();
            $workflow = WorkflowRecord::create([
                'id' => (string) Str::uuid(),
                'subject_type' => $definition->subjectType,
                'subject_id' => $definition->subjectId,
                'metadata' => $definition->metadata,
                'title' => $definition->title,
                'description' => $definition->description,
                'canonical' => $definition->canonical,
                'status' => $control->cooldown ? 'held' : 'running',
                'started_at' => $control->cooldown ? null : now(),
            ]);
            /**
             * Save every step before checking which steps can start. Progression records jobs
             * to send in the same transaction; afterCommit publishes only once the outermost
             * transaction succeeds, so a worker cannot see records that may still roll back.
             */
            app(NodeStore::class)->store($workflow->id, $definition);
            $this->progression->advance($workflow);
            $this->delivery->afterCommit($workflow->id);

            return $workflow;
        }, (int) config('scarlett.transaction_attempts'));
    }

    /** A workflow lock serializes claims, outcomes, cancellation, recovery and admission. */
    private function mutate(string $stepId, Closure $operation, bool $advance = true): mixed
    {
        /**
         * Find and lock the workflow before locking this step. Every state-changing path
         * uses that order, so cancellation, recovery, and result persistence serialize.
         * Only successful operations trigger another check for newly eligible work.
         */
        return $this->attemptLock->mutate(
            $stepId,
            fn (string $id): mixed => StepRecord::whereKey($id)->toBase()->value('workflow_id'),
            fn (WorkflowRecord $workflow, string $id): ?StepRecord => $workflow->steps()->whereKey($id)->lockForUpdate()->first(),
            $operation,
            $advance ? function (WorkflowRecord $workflow, string $workflowId): void {
                $this->progression->advance($workflow);
                $this->delivery->afterCommit($workflowId);
            } : null,
        );
    }

    public function claim(string $stepId, string $attempt): ?StepRecord
    {
        return $this->mutate($stepId, function (StepRecord $step) use ($attempt): ?StepRecord {
            /**
             * A job can arrive twice, arrive early, or belong to a replaced attempt. Claim
             * only the queued token whose scheduled time has arrived. Once Running, the same
             * job cannot claim it again; the lease now bounds how long this worker owns it.
             */
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
        /**
         * Ownership requires all three facts now: this token is current, the step is
         * running, and its deadline has not passed. Revocation changes state, while
         * recovery may replace the token; either must make an old worker lose authority.
         */
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
        /**
         * Convert the return value into the package's stored payload format before taking
         * mutation locks. Unsupported results fail here; no success checkpoint is saved.
         */
        $payload = $this->codec->encode($result);

        return (bool) $this->mutate($stepId, function (StepRecord $step) use ($attempt, $payload, $completionState): bool {
            if (! $this->owns($step, $attempt)) {
                return false;
            }
            /**
             * Save a checkpoint saying the business action finished, including a valid null
             * result. Verification can then retry without repeating that action. A step with
             * children must also be allowed to reach its chosen success state after waiting.
             */
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
            /**
             * Finishing an action is not always finishing the step. Predeclared children must
             * run before their parent settles. A runtime parent returning from child-result
             * verification has already waited, so it must not enter the child phase again.
             */
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
            /**
             * A decision can hand off newly discovered children, settle unsuccessfully, wait
             * for a throttle, or retry. Child handoff is allowed only before saving the action:
             * otherwise replaying verification could create a second set of children.
             */
            if ($decision->children !== null) {
                if ($verification || $step->action_completed) {
                    throw new \InvalidArgumentException('Children can only be handed off before the action is persisted.');
                }
                app(RuntimeChildren::class)->admit($step, $decision);

                return true;
            }
            $error = $decision->reason ?? $error;
            $step->error = $error;
            /**
             * Successful outcomes must go through saveAction() and verification. This branch
             * is for states that do not publish an accepted result, such as failure or skip;
             * changing state here also removes the worker's execution lease.
             */
            if ($decision->state !== null) {
                if ($decision->producesResult) {
                    throw new \InvalidArgumentException('Successful decisions must pass action persistence and verification.');
                }
                $step->lease_until = null;
                $step->save();
                $this->journal->transition($step, $decision->state, $error, $cause);

                return true;
            }
            /**
             * Throttling means work was postponed, not that an action attempt failed. Queue
             * a fresh token without spending the failure budget, and impose a minimum wait
             * when the policy gives zero so workers cannot immediately loop on the same limit.
             */
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
            /**
             * Run the caller's database writes only after rechecking ownership inside the
             * same lock as cancellation. An earlier isCurrent() read could become stale
             * between reading authority and saving the caller's business changes.
             */
            $write();

            return true;
        }, advance: false);
    }

    public function recover(string $stepId): bool
    {
        return (bool) $this->mutate($stepId, function (StepRecord $step): bool {
            /**
             * Queued work can be stranded because publication failed or a sent job was lost.
             * Reuse a replayable delivery record rather than inventing another action attempt.
             * The replay check respects the publisher's own lease to avoid stealing its work.
             */
            if ($step->state::phase() === Phase::Queued && ! $step->available_at->isFuture()) {
                $delivery = $this->delivery->replayable($step)->lockForUpdate()->first();
                if ($delivery === null) {
                    return false;
                }
                /**
                 * MySQL reports zero changed rows when an unsent intent already has these values.
                 * Eligibility, not the update count, determines whether to publish after commit.
                 */
                DB::table('scarlett_deliveries')->where('id', $delivery->id)->update(['sent_at' => null, 'updated_at' => now()]);

                return true;
            }
            if ($step->state::phase() !== Phase::Running || $step->lease_until === null || $step->lease_until->isFuture()) {
                return false;
            }
            /**
             * A running worker whose lease expired has lost authority. Preserve the action
             * checkpoint: if its result was already saved, retry verification, not the action.
             * Otherwise retry the action using its separate remaining failure budget.
             */
            $step->error = 'Execution lease expired.';
            $this->retry($step, $step->action_completed, cause: FailureCause::AttemptLost);

            return true;
        });
    }

    /** Action and verification counters are independent; throttles never enter this path. */
    private function retry(StepRecord $step, bool $verification, ?int $delay = null, FailureCause $cause = FailureCause::BudgetExhausted): void
    {
        /**
         * Action errors and verification errors spend independent budgets. Ask the policy
         * with the next failure count, then either queue a fresh attempt after backoff or
         * settle as failed. Throttling deliberately bypasses this accounting.
         */
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
