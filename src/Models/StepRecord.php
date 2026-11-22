<?php

declare(strict_types=1);

/**
 * The saved definition and execution checkpoint for one workflow step.
 *
 * The node key connects graph links; the row UUID identifies this particular record.
 * Attempt tokens change on each queueing. State and lease decide who can still save.
 * action_completed prevents business replay; has_result distinguishes a saved null
 * from no result. Inputs are recipes, while result is a versioned encoded payload.
 * Use Engine to change execution state so locking, history, and progression stay aligned.
 */

namespace Scarlett\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Scarlett\Definition\ExecutionOptions;
use Scarlett\Execution\ChildPhase;
use Scarlett\States\StepState;
use Spatie\ModelStates\HasStates;

/**
 * Persisted definition, latest attempt and durable action result.
 * Use engine operations for writes; direct state updates bypass graph and journal invariants.
 */
class StepRecord extends Model
{
    use HasStates;

    protected $table = 'scarlett_steps';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'workflow_id',
        'key',
        'class',
        'title',
        'description',
        'canonical',
        'signature',
        'arguments',
        'state',
        'parent_key',
        'rescues_key',
        'dependencies',
        'inputs',
        'result',
        'has_result',
        'completion_state',
        'child_phase',
        'child_result_key',
        'priority',
        'queue',
        'connection',
        'attempt',
        'failures',
        'verifications',
        'action_completed',
        'available_at',
        'lease_until',
        'error',
        'execution',
        'throttle_reservations',
        'not_before',
        'started_at',
        'finished_at',
    ];

    protected function casts(): array
    {
        return [
            'state' => StepState::class,
            'execution' => 'array',
            'throttle_reservations' => 'array',
            'child_phase' => ChildPhase::class,
            'not_before' => 'immutable_datetime',
            'started_at' => 'immutable_datetime',
            'finished_at' => 'immutable_datetime',
            'dependencies' => 'array',
            'inputs' => 'array',
            'signature' => 'array',
            'arguments' => 'array',
            'result' => 'array',
            'has_result' => 'boolean',
            'action_completed' => 'boolean',
            'available_at' => 'immutable_datetime',
            'lease_until' => 'immutable_datetime',
            'priority' => 'integer',
            'failures' => 'integer',
            'verifications' => 'integer',
        ];
    }

    public function nextFailureCount(bool $verification = false): int
    {
        return ($verification ? $this->verifications : $this->failures) + 1;
    }

    public function executionOptions(): array
    {
        /**
         * Prefer the settings saved when this step was registered. Only older rows with
         * no execution snapshot use current defaults; active saved budgets remain stable.
         */
        return $this->execution ?? (new ExecutionOptions)->snapshot();
    }

    public function workflow(): BelongsTo
    {
        return $this->belongsTo(WorkflowRecord::class, 'workflow_id');
    }
}
