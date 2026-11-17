<?php

declare(strict_types=1);

/**
 * The database row representing one workflow's overall lifecycle and ownership.
 *
 * All its steps and failure hooks share this row as their first mutation lock.
 * started_at distinguishes held work from authorized work; finished_at is set only
 * after steps and terminal hooks settle. Results belong to steps, not this row.
 * Read relationships here, but use Engine operations for lifecycle changes.
 */

namespace Scarlett\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The aggregate and lock boundary shared by every step and failure hook in a workflow.
 */
class WorkflowRecord extends Model
{
    protected $table = 'scarlett_workflows';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['id', 'status', 'started_at', 'finished_at', 'archived_at', 'subject_type', 'subject_id', 'metadata', 'title', 'description', 'canonical'];

    protected function casts(): array
    {
        return ['metadata' => 'array', 'started_at' => 'immutable_datetime', 'finished_at' => 'immutable_datetime', 'archived_at' => 'immutable_datetime'];
    }

    public function scopeForSubject(Builder $query, string $type, string $id): Builder
    {
        return $query->where('subject_type', $type)->where('subject_id', $id);
    }

    public function failures(): HasMany
    {
        return $this->hasMany(FailureRecord::class, 'workflow_id');
    }

    public function steps(): HasMany
    {
        return $this->hasMany(StepRecord::class, 'workflow_id');
    }
}
