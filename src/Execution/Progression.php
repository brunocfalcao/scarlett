<?php

declare(strict_types=1);

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
        $childrenByParent = $steps->groupBy('parent_key');
        $branchesByOwner = $steps->groupBy(fn (StepRecord $step): ?string => $step->parent_key ?? $step->rescues_key);
        do {
            $changed = false;
            foreach ($steps as $step) {
                $phase = $step->state::phase();
                if ($phase === Phase::Children) {
                    $children = $childrenByParent->get($step->key, collect());
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
        if ($step->child_phase !== ChildPhase::Waiting) {
            $this->journal->conclude($step, $step->completion_state ?? Completed::class);

            return;
        }
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
