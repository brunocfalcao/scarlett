<?php

declare(strict_types=1);

namespace Scarlett\Execution;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Scarlett\Models\StepRecord;
use Scarlett\Models\WorkflowRecord;
use Scarlett\States\Cancelled;
use Scarlett\States\Completed;
use Scarlett\States\Failed;
use Scarlett\States\Phase;
use Scarlett\States\Queued;
use Scarlett\States\Skipped;

final class Progression
{
    public function __construct(private Journal $journal) {}

    /** All eligibility changes and delivery intents share the caller's workflow transaction. */
    public function advance(WorkflowRecord $workflow): void
    {
        if ($workflow->finished_at !== null) {
            return;
        }
        do {
            $changed = false;
            $steps = $workflow->steps()->orderByDesc('priority')->get()->keyBy('key');
            foreach ($steps as $step) {
                $phase = $step->state::phase();
                if ($phase === Phase::Children) {
                    $children = $steps->where('parent_key', $step->key);
                    if ($children->contains(fn ($child): bool => $child->state::activatesRecovery())) {
                        $this->journal->transition($step, Failed::class, 'A child failed.');
                        $changed = true;

                        continue;
                    }
                    if ($children->contains(fn ($child): bool => $child->state::phase() === Phase::Settled && ! $child->state::satisfiesDependency())) {
                        $this->journal->transition($step, Cancelled::class, 'A child was cancelled.');
                        $changed = true;

                        continue;
                    }
                    if ($children->every(fn ($child): bool => $child->state::phase() === Phase::Settled)) {
                        $successful = $children->every(fn ($child): bool => $child->state::satisfiesDependency());
                        $this->journal->transition($step, $successful ? ($step->completion_state ?? Completed::class) : Failed::class);
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
                $dependencies = $steps->filter(fn (StepRecord $candidate): bool => in_array($candidate->key, $step->dependencies, true));
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
        $this->aggregate($workflow);
    }

    private function aggregate(WorkflowRecord $workflow): void
    {
        $steps = $workflow->steps()->get();
        if ($steps->every(fn ($step): bool => $step->state::phase() === Phase::Settled)) {
            $failed = $steps->contains(fn ($step): bool => $step->state::activatesRecovery());
            // Unused recovery nodes do not make a successful workflow cancelled.
            $cancelled = $steps->contains(fn ($step): bool => $step->rescues_key === null && ! $step->state::satisfiesDependency());
            $workflow->update(['status' => $failed ? 'failed' : ($cancelled ? 'cancelled' : 'completed'), 'finished_at' => now()]);
        }
    }

    public function enqueue(StepRecord $step, int $delay = 0): void
    {
        $step->fill(['attempt' => (string) Str::uuid(), 'available_at' => now()->addSeconds($delay), 'lease_until' => null])->save();
        $this->journal->transition($step, Queued::class, $step->error);
        DB::table('scarlett_deliveries')->insert([
            'id' => (string) Str::uuid(), 'step_id' => $step->id, 'attempt' => $step->attempt,
            'available_at' => $step->available_at, 'sent_at' => null, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
