<?php

declare(strict_types=1);

namespace Scarlett;

use DateTimeInterface;
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
        $removed = 0;
        foreach (WorkflowRecord::where('archived_at', '<', $before)->cursor() as $candidate) {
            $removed += DB::transaction(function () use ($candidate, $before): int {
                $workflow = WorkflowRecord::whereKey($candidate->id)->lockForUpdate()->first();
                if (! $workflow || ! $this->settled($workflow) || $workflow->archived_at === null || $workflow->archived_at >= $before) {
                    return 0;
                }
                $ids = $workflow->steps()->pluck('id');
                DB::table('scarlett_history')->whereIn('step_id', $ids)->delete();
                DB::table('scarlett_deliveries')->whereIn('step_id', $ids)->delete();
                $workflow->steps()->delete();
                $workflow->delete();

                return 1;
            });
        }

        return $removed;
    }

    private function settled(WorkflowRecord $workflow): bool
    {
        return $workflow->finished_at !== null && $workflow->steps()->get()->every(fn ($step): bool => $step->state::phase() === Phase::Settled);
    }
}
