<?php

declare(strict_types=1);

namespace Scarlett;

use DateTimeInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Scarlett\Models\WorkflowRecord;
use Scarlett\States\Phase;

final class History
{
    public function archive(string $workflowId): bool
    {
        return DB::transaction(function () use ($workflowId): bool {
            $workflow = WorkflowRecord::whereKey($workflowId)->lockForUpdate()->firstOrFail();
            if (! $this->settled($workflow)) {
                return false;
            }
            $workflow->update(['archived_at' => now()]);

            return true;
        });
    }

    /** Retention age is caller-supplied; no active workflow is eligible. */
    public function purge(DateTimeInterface $before): int
    {
        $before = Carbon::instance($before)->setTimezone(config('app.timezone'));
        $removed = 0;
        foreach (WorkflowRecord::where('archived_at', '<', $before)->cursor() as $candidate) {
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

    /** Explicit subject cleanup after the caller settles or revokes the workflow. */
    public function delete(string $workflowId): bool
    {
        return DB::transaction(function () use ($workflowId): bool {
            $workflow = WorkflowRecord::whereKey($workflowId)->lockForUpdate()->first();
            if ($workflow === null || ! $this->settled($workflow)) {
                return false;
            }
            $this->remove($workflow);

            return true;
        });
    }

    private function remove(WorkflowRecord $workflow): void
    {
        $ids = $workflow->steps()->pluck('id');
        DB::table('scarlett_history')->whereIn('step_id', $ids)->delete();
        DB::table('scarlett_deliveries')->whereIn('step_id', $ids)->delete();
        $workflow->steps()->delete();
        $workflow->delete();
    }

    private function settled(WorkflowRecord $workflow): bool
    {
        return $workflow->finished_at !== null && $workflow->steps()->get()->every(fn ($step): bool => $step->state::phase() === Phase::Settled);
    }
}
