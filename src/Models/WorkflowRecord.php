<?php

declare(strict_types=1);

namespace Scarlett\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WorkflowRecord extends Model
{
    protected $table = 'scarlett_workflows';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['id', 'status', 'started_at', 'finished_at', 'archived_at'];

    protected function casts(): array
    {
        return ['started_at' => 'immutable_datetime', 'finished_at' => 'immutable_datetime', 'archived_at' => 'immutable_datetime'];
    }

    public function steps(): HasMany
    {
        return $this->hasMany(StepRecord::class, 'workflow_id');
    }
}
