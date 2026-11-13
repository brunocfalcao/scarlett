<?php

declare(strict_types=1);

namespace Scarlett\Execution;

use Illuminate\Support\Facades\DB;
use Scarlett\Failures\Coordinator;
use Scarlett\Models\StepRecord;
use Scarlett\States\Failed;
use Scarlett\States\Phase;
use Spatie\ModelStates\Exceptions\TransitionNotAllowed;
use Spatie\ModelStates\Exceptions\TransitionNotFound;

final class Journal
{
    /** A denied completion belongs to this step, including when its last child triggers it. */
    public function conclude(StepRecord $step, string $state): void
    {
        try {
            $this->transition($step, $state);
        } catch (TransitionNotAllowed|TransitionNotFound $exception) {
            $this->transition($step, Failed::class, 'Completion rejected: '.$exception->getMessage(), FailureCause::CompletionRejected);
        }
    }

    /** Called only while holding the workflow lock. */
    public function transition(StepRecord $step, string $state, ?string $reason = null, FailureCause $cause = FailureCause::Decided): void
    {
        $from = (string) $step->state;
        $step->state->transitionTo($state);
        if ($step->state::phase() === Phase::Running) {
            $step->update(['started_at' => now()]);
        } elseif ($step->state::phase() === Phase::Settled) {
            $attributes = ['finished_at' => now(), 'lease_until' => null];
            if (! $step->state::satisfiesDependency() && $reason !== null) {
                $attributes['error'] = $reason;
            }
            $step->update($attributes);
        }
        if ($step->state::phase() === Phase::Settled && $step->state::activatesRecovery()) {
            app(Coordinator::class)->register($step, $cause, $reason);
        }
        DB::table('scarlett_history')->insert([
            'step_id' => $step->id, 'attempt' => $step->attempt,
            'from_state' => $from, 'to_state' => (string) $step->state,
            'reason' => $reason, 'created_at' => now(),
        ]);
    }
}
