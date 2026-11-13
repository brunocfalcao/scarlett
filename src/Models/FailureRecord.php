<?php

declare(strict_types=1);

namespace Scarlett\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Scarlett\Execution\FailureCause;
use Scarlett\Failures\Status;

class FailureRecord extends Model
{
    protected $table = 'scarlett_failures';
    public $incrementing = false;
    protected $keyType = 'string';
    protected $fillable = ['id', 'workflow_id', 'step_id', 'cause', 'reason', 'snapshot', 'status', 'attempt', 'failures', 'available_at', 'lease_until', 'sent_at', 'publishing_until', 'finished_at', 'error'];

    protected function casts(): array
    {
        return ['cause' => FailureCause::class, 'status' => Status::class, 'snapshot' => 'array', 'failures' => 'integer',
            'available_at' => 'immutable_datetime', 'lease_until' => 'immutable_datetime', 'sent_at' => 'immutable_datetime',
            'publishing_until' => 'immutable_datetime', 'finished_at' => 'immutable_datetime'];
    }

    public function scopeUnfinished(Builder $query): Builder
    {
        return $query->whereIn('status', [Status::Pending->value, Status::Queued->value, Status::Running->value]);
    }

    public function step(): BelongsTo
    {
        return $this->belongsTo(StepRecord::class, 'step_id');
    }

    public function workflow(): BelongsTo
    {
        return $this->belongsTo(WorkflowRecord::class, 'workflow_id');
    }
}
