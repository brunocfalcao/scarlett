<?php

declare(strict_types=1);

namespace Scarlett\Execution;

use Closure;
use Illuminate\Support\Facades\DB;
use Scarlett\Models\WorkflowRecord;

/** Serializes an attempt record with its workflow before applying a guarded mutation. */
final class WorkflowAttemptLock
{
    public function mutate(string $recordId, Closure $workflowId, Closure $record, Closure $operation, ?Closure $after = null): mixed
    {
        $workflowKey = $workflowId($recordId);
        if ($workflowKey === null) {
            return null;
        }

        return DB::transaction(function () use ($recordId, $workflowKey, $record, $operation, $after): mixed {
            $workflow = WorkflowRecord::whereKey($workflowKey)->lockForUpdate()->first();
            if (! $workflow) {
                return null;
            }
            $attempt = $record($workflow, $recordId);
            if ($attempt === null) {
                return null;
            }
            $result = $operation($attempt);
            if ($after !== null && $result !== null && $result !== false) {
                $after($workflow, $workflowKey);
            }

            return $result;
        }, (int) config('scarlett.transaction_attempts'));
    }
}
