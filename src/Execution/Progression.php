<?php

declare(strict_types=1);

/**
 * Decides what can run next after a workflow record changes.
 *
 * The caller holds the workflow lock. All step states are loaded together so one
 * change can release a later stage, finish a parent, or activate failure recovery.
 * A settled step has reached a final state; satisfying a dependency means its state
 * allows later work to proceed. Those concepts differ: failure is final but does
 * not release ordinary successors. No business action runs inside this traversal.
 */

namespace Scarlett\Execution;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Scarlett\Failures\Coordinator;
use Scarlett\Models\StepRecord;
use Scarlett\Models\WorkflowRecord;
use Scarlett\States\Cancelled;
use Scarlett\States\Completed;
use Scarlett\States\Failed;
use Scarlett\States\Phase;
use Scarlett\States\Queued;
use Scarlett\States\Skipped;

/**
 * Reaches a stable graph outcome inside the current workflow transaction.
 * Rescue branches drain bottom-up; successful rescue does not reopen failed work.
 */
final class Progression
{
    public function __construct(private Journal $journal) {}

    /** All eligibility changes and delivery intents share the caller's workflow transaction. */
    public function advance(WorkflowRecord $workflow): void
    {
        if ($workflow->finished_at !== null) {
            return;
        }
        // The caller may already hold an older MySQL snapshot; progression needs current outcomes.
        $steps = $workflow->steps()->orderByDesc('priority')->lockForUpdate()->get()->keyBy('key');
        /**
         * Build child and owned-branch indexes once. The same graph is examined repeatedly,
         * and looking up a group by owner avoids scanning every step for each parent.
         */
        $childrenByParent = $steps->groupBy('parent_key');
        $branchesByOwner = $steps->groupBy(fn (StepRecord $step): ?string => $step->parent_key ?? $step->rescues_key);
        /**
         * Repeat until a complete pass makes no changes. A child may finish after its
         * parent was visited in this pass; another pass lets that parent settle immediately
         * and release later work without waiting for a scheduler or a separate queue job.
         */
        do {
            $changed = false;
            foreach ($steps as $step) {
                $phase = $step->state::phase();
                if ($phase === Phase::Children) {
                    $children = $childrenByParent->get($step->key, collect());
                    /**
                     * A failed descendant stops siblings that have not started, but already running
                     * branches and their rescues must drain before the parent fails. Settling the
                     * parent early could release its rescue while child work was still changing data.
                     */
                    if ($this->hasFailedChild($step, $childrenByParent)) {
                        // Failure closes ordinary admission while running children and their recovery drain.
                        foreach ($children as $child) {
                            if (in_array($child->state::phase(), [Phase::Pending, Phase::Queued], true)) {
                                $this->journal->transition($child, Cancelled::class, 'A sibling failed.');
                                $changed = true;
                            }
                        }
                        if ($children->every(fn (StepRecord $child): bool => $this->branchSettled($child, $branchesByOwner))) {
                            $this->journal->transition($step, Failed::class, 'A child failed.', FailureCause::ChildFailed);
                            $changed = true;
                        }

                        continue;
                    }
                    if ($children->contains(fn ($child): bool => $child->state::phase() === Phase::Settled && ! $child->state::satisfiesDependency())) {
                        foreach ($children as $child) {
                            if (in_array($child->state::phase(), [Phase::Pending, Phase::Queued], true)) {
                                $this->journal->transition($child, Cancelled::class, 'A sibling was cancelled.');
                                $changed = true;
                            }
                        }
                        if ($children->every(fn (StepRecord $child): bool => $this->branchSettled($child, $branchesByOwner))) {
                            $this->journal->transition($step, Cancelled::class, 'A child was cancelled.');
                            $changed = true;
                        }

                        continue;
                    }
                    if ($children->every(fn ($child): bool => $child->state::phase() === Phase::Settled)) {
                        $this->completeChildren($step, $steps);
                        $changed = true;
                    }

                    continue;
                }
                if (! in_array($phase, [Phase::Pending, Phase::Queued], true)) {
                    continue;
                }
                /**
                 * A rescue waits for its owner to finish. Run it only if that final state calls
                 * for recovery; otherwise mark it skipped so unused error-handling work cannot
                 * keep a successful workflow open forever.
                 */
                if ($step->rescues_key !== null) {
                    $owner = $steps[$step->rescues_key];
                    if ($owner->state::phase() !== Phase::Settled) {
                        continue;
                    }
                    if (! $owner->state::activatesRecovery()) {
                        $this->journal->transition($step, Skipped::class, 'Recovery not required.');
                        $changed = true;

                        continue;
                    }
                }
                /**
                 * Children start only while their parent is waiting for them. If the parent
                 * already concluded, close unused children instead of letting stale deliveries
                 * start business work under an owner that no longer authorizes it.
                 */
                if ($step->parent_key !== null) {
                    $parent = $steps[$step->parent_key];
                    if ($parent->state::phase() === Phase::Settled) {
                        $this->journal->transition($step, $parent->state::skipsChildren() ? Skipped::class : Cancelled::class, 'Parent concluded before this child started.');
                        $changed = true;

                        continue;
                    }
                    if ($parent->state::phase() !== Phase::Children) {
                        continue;
                    }
                }
                /**
                 * Stage ordering was saved as prerequisite keys. Wait until all have an accepting
                 * state; if one ended unsuccessfully, this step can never become eligible and is
                 * cancelled instead. The queue state check below avoids issuing duplicate attempts.
                 */
                $dependencies = collect();
                foreach ($step->dependencies as $dependencyKey) {
                    $dependency = $steps->get($dependencyKey);
                    if ($dependency !== null) {
                        $dependencies->push($dependency);
                    }
                }
                if ($dependencies->contains(fn ($dependency): bool => $dependency->state::phase() === Phase::Settled && ! $dependency->state::satisfiesDependency())) {
                    $this->journal->transition($step, Cancelled::class, 'Prerequisite did not succeed.');
                    $changed = true;

                    continue;
                }
                if (! $dependencies->every(fn ($dependency): bool => $dependency->state::satisfiesDependency())) {
                    continue;
                }
                if ($phase === Phase::Queued || $workflow->started_at === null) {
                    continue;
                }
                $this->enqueue($step);
                $changed = true;
            }
        } while ($changed);
        app(Coordinator::class)->advance($workflow, $steps);
        $this->aggregate($workflow, $steps);
    }

    /** Runtime parents verify the effective result after their child plan settles. */
    private function completeChildren(StepRecord $step, Collection $steps): void
    {
        /**
         * Predeclared children finish an already verified parent directly. Runtime plans
         * instead defer parent verification until their children produce the final value,
         * so those parents need one more queued verification phase.
         */
        if ($step->child_phase !== ChildPhase::Waiting) {
            $this->journal->conclude($step, $step->completion_state ?? Completed::class);

            return;
        }
        /**
         * A runtime plan may select one direct child's result as its parent's result.
         * Require a real saved result, including a saved null; a skipped child has no
         * result to forward even though its state may allow dependencies to proceed.
         */
        if ($step->child_result_key !== null) {
            $source = $steps->get($step->child_result_key);
            if ($source === null || ! $source->has_result) {
                $this->journal->transition($step, Failed::class, 'The selected child did not produce a result.', FailureCause::ChildResultMissing);

                return;
            }
            $step->result = $source->result;
            $step->has_result = true;
        }
        $step->child_phase = ChildPhase::Verifying;
        $step->save();
        $this->enqueue($step);
    }

    /** A waiting child can already be draining a failure further down the ordinary tree. */
    private function hasFailedChild(StepRecord $parent, Collection $childrenByParent): bool
    {
        return $childrenByParent->get($parent->key, collect())->contains(
            fn (StepRecord $child): bool => $child->state::activatesRecovery()
                || ($child->state::phase() === Phase::Children && $this->hasFailedChild($child, $childrenByParent)),
        );
    }

    /** Recovery is bottom-up: include nested children and rescues of rescues, but not the waiting parent's own rescues. */
    private function branchSettled(StepRecord $step, Collection $branchesByOwner): bool
    {
        return $step->state::phase() === Phase::Settled
            && $branchesByOwner->get($step->key, collect())->every(
                fn (StepRecord $branch): bool => $this->branchSettled($branch, $branchesByOwner),
            );
    }

    private function aggregate(WorkflowRecord $workflow, Collection $steps): void
    {
        /**
         * The workflow is finished only when every step and durable failure hook is done.
         * A rescue succeeding does not erase its owner's failure; aggregate status must
         * still describe that original outcome.
         */
        if ($steps->every(fn ($step): bool => $step->state::phase() === Phase::Settled) && $workflow->failures()->unfinished()->lockForUpdate()->first() === null) {
            $failed = $steps->contains(fn ($step): bool => $step->state::activatesRecovery());
            // Unused recovery nodes do not make a successful workflow cancelled.
            $cancelled = $steps->contains(fn ($step): bool => $step->rescues_key === null && ! $step->state::satisfiesDependency());
            $workflow->update(['status' => $failed ? 'failed' : ($cancelled ? 'cancelled' : 'completed'), 'finished_at' => now()]);
        }
    }

    /** A fresh attempt token and its outbox intent must commit together before publication. */
    public function enqueue(StepRecord $step, int $delay = 0): void
    {
        /**
         * Respect both retry delay and the definition's earliest-start time. Generate a
         * new token and save its outbox request together, so old deliveries lose authority
         * and recovery can publish the new attempt if the queue send is interrupted.
         */
        $availableAt = now()->addSeconds($delay);
        if ($step->not_before !== null && $step->not_before->greaterThan($availableAt)) {
            $availableAt = $step->not_before;
        }
        $step->fill(['attempt' => (string) Str::uuid(), 'available_at' => $availableAt, 'lease_until' => null])->save();
        $this->journal->transition($step, Queued::class, $step->error);
        DB::table('scarlett_deliveries')->insert([
            'id' => (string) Str::uuid(), 'step_id' => $step->id, 'attempt' => $step->attempt,
            'available_at' => $step->available_at, 'sent_at' => null, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
