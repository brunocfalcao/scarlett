<?php

declare(strict_types=1);

/**
 * Archives or removes an entire workflow's retained execution records.
 *
 * Archiving adds a timestamp in place; it does not move rows to another table.
 * Purging requires old archived history that is still fully settled when locked.
 * Explicit deletion may also revoke running work when requested. Delete dependent
 * rows deliberately, in foreign-key order, instead of relying on cascading deletes.
 * Slot pointers have no foreign key; current-slot reads ignore missing workflows,
 * and SubjectDeletion explicitly removes the deleted subject's slots.
 */

namespace Scarlett;

use DateTimeInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Scarlett\Models\WorkflowRecord;
use Scarlett\States\Phase;

/**
 * Retains or removes whole workflow graphs under their owning lock.
 * Archive is an in-place marker; deletion explicitly removes dependent records.
 */
final class History
{
    public const DEFAULT_BATCH_SIZE = 100;

    public function archive(string $workflowId): bool
    {
        return DB::transaction(function () use ($workflowId): bool {
            $workflow = WorkflowRecord::whereKey($workflowId)->lockForUpdate()->firstOrFail();

            return $this->archiveLocked($workflow);
        });
    }

    public function archiveBefore(DateTimeInterface $before, int $batchSize = self::DEFAULT_BATCH_SIZE): int
    {
        $this->validateBatchSize($batchSize);
        $before = Carbon::instance($before)->setTimezone(config('app.timezone'));
        $archived = 0;
        /**
         * Fetch in bounded batches, then reread each candidate under its workflow lock.
         * Selection can become stale while another process changes history; only the
         * locked recheck decides whether this workflow is still eligible for archiving.
         */
        $candidates = WorkflowRecord::whereNull('archived_at')->where('finished_at', '<', $before);
        foreach ($candidates->lazyById($batchSize) as $candidate) {
            $archived += DB::transaction(function () use ($candidate, $before): int {
                $workflow = WorkflowRecord::whereKey($candidate->id)->lockForUpdate()->first();
                if ($workflow === null || $workflow->finished_at === null || $workflow->finished_at >= $before) {
                    return 0;
                }

                return (int) $this->archiveLocked($workflow);
            });
        }

        return $archived;
    }

    private function archiveLocked(WorkflowRecord $workflow): bool
    {
        if ($workflow->archived_at !== null || ! $this->settled($workflow)) {
            return false;
        }
        $workflow->update(['archived_at' => now()]);

        return true;
    }

    /** Retention age is caller-supplied; no active workflow is eligible. */
    public function purge(DateTimeInterface $before, int $batchSize = self::DEFAULT_BATCH_SIZE): int
    {
        $this->validateBatchSize($batchSize);
        $before = Carbon::instance($before)->setTimezone(config('app.timezone'));
        $removed = 0;
        foreach (WorkflowRecord::where('archived_at', '<', $before)->lazyById($batchSize) as $candidate) {
            $removed += DB::transaction(function () use ($candidate, $before): int {
                $workflow = WorkflowRecord::whereKey($candidate->id)->lockForUpdate()->first();
                if (! $workflow || ! $this->settled($workflow) || $workflow->archived_at === null || $workflow->archived_at >= $before) {
                    return 0;
                }
                $this->remove($workflow);

                return 1;
            });
        }

        return $removed;
    }

    private function validateBatchSize(int $batchSize): void
    {
        if ($batchSize < 1) {
            throw new InvalidArgumentException('Retention batch size must be a positive integer.');
        }
    }

    /** Explicit deletion; includingRunning withdraws authority atomically by removing its records. */
    public function delete(string $workflowId, bool $includingRunning = false): bool
    {
        return DB::transaction(function () use ($workflowId, $includingRunning): bool {
            $workflow = WorkflowRecord::whereKey($workflowId)->lockForUpdate()->first();
            if ($workflow === null || (! $includingRunning && ! $this->settled($workflow))) {
                return false;
            }
            $this->remove($workflow);

            return true;
        });
    }

    /** Explicit dependency order preserves restrictive foreign keys without cascading deletion. */
    private function remove(WorkflowRecord $workflow): void
    {
        /**
         * Remove failure records, transition history, and queue intents before steps;
         * remove the workflow last. Restrictive foreign keys make this order necessary.
         * This deletes retained engine data, not any external effects of the business action.
         */
        $ids = $workflow->steps()->orderBy('id')->lockForUpdate()->pluck('id');
        $workflow->failures()->delete();
        DB::table('scarlett_history')->whereIn('step_id', $ids)->delete();
        DB::table('scarlett_deliveries')->whereIn('step_id', $ids)->delete();
        $workflow->steps()->delete();
        $workflow->delete();
    }

    private function settled(WorkflowRecord $workflow): bool
    {
        return $workflow->finished_at !== null && $workflow->failures()->unfinished()->lockForUpdate()->first() === null && $workflow->steps()->lockForUpdate()->get()->every(fn ($step): bool => $step->state::phase() === Phase::Settled);
    }
}
