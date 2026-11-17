<?php

declare(strict_types=1);

/**
 * Keeps a step's state, lifecycle timestamps, and transition history in agreement.
 *
 * A transition means changing state through the configured state machine, not just
 * assigning a string. When a step finishes unsuccessfully, register its durable
 * failure hook here so the state change and follow-up work share the same transaction.
 * Callers must already hold the workflow lock; this class does not acquire it.
 */

namespace Scarlett\Execution;

use Illuminate\Support\Facades\DB;
use Scarlett\Failures\Coordinator;
use Scarlett\Models\StepRecord;
use Scarlett\States\Failed;
use Scarlett\States\Phase;
use Spatie\ModelStates\Exceptions\TransitionNotAllowed;
use Spatie\ModelStates\Exceptions\TransitionNotFound;

/**
 * Persists state transitions, timestamps and terminal-hook registration together.
 * Callers must hold the workflow lock so history and progression see one outcome.
 */
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
        /**
         * Let the state package enforce allowed transitions first. Only then update
         * execution timestamps and history; an invalid transition must not produce a
         * history entry claiming that it happened.
         */
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
