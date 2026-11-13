<?php

declare(strict_types=1);

namespace Scarlett\Execution;

use Illuminate\Support\Facades\DB;
use Scarlett\Models\StepRecord;

final class Journal
{
    /** Called only while holding the workflow lock. */
    public function transition(StepRecord $step, string $state, ?string $reason = null): void
    {
        $from = (string) $step->state;
        $step->state->transitionTo($state);
        DB::table('scarlett_history')->insert([
            'step_id' => $step->id, 'attempt' => $step->attempt,
            'from_state' => $from, 'to_state' => (string) $step->state,
            'reason' => $reason, 'created_at' => now(),
        ]);
    }
}
