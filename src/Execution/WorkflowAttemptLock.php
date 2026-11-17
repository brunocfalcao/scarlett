<?php

declare(strict_types=1);

/**
 * Shares the lock order used for action attempts and terminal failure-hook attempts.
 *
 * Callbacks describe how to find and lock either kind of record. The workflow is
 * always locked first, then the attempt record is reread under that lock. This
 * prevents deletion, cancellation, or recovery from racing an outcome save. A
 * missing record returns null because a queued job may outlive the work it names.
 */

namespace Scarlett\Execution;

use Closure;
use Illuminate\Support\Facades\DB;
use Scarlett\Models\WorkflowRecord;

/** Serializes an attempt record with its workflow before applying a guarded mutation. */
final class WorkflowAttemptLock
{
    public function mutate(string $recordId, Closure $workflowId, Closure $record, Closure $operation, ?Closure $after = null): mixed
    {
        /**
         * The first lookup only finds which workflow to lock; it grants no authority.
         * The record is looked up again after taking that lock because another transaction
         * may cancel or delete it between these two reads.
         */
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
            /**
             * Null and false mean the mutation did not accept this operation. Do not advance
             * or publish follow-up work for rejected stale attempts. Successful callbacks may
             * return other values, so test those two rejection values explicitly.
             */
            if ($after !== null && $result !== null && $result !== false) {
                $after($workflow, $workflowKey);
            }

            return $result;
        }, (int) config('scarlett.transaction_attempts'));
    }
}
