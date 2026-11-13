<?php

declare(strict_types=1);

namespace Scarlett;

use Illuminate\Support\Facades\DB;
use Scarlett\Models\StepRecord;
use Scarlett\States\Phase;

final class Recovery
{
    public function stalled(?string $workflowId = null): bool
    {
        foreach ($this->candidates($workflowId) as $step) {
            if ($this->needsRecovery($step)) {
                return true;
            }
        }

        return false;
    }

    public function resume(?string $workflowId = null): int
    {
        $recovered = 0;
        foreach ($this->candidates($workflowId) as $step) {
            if ($this->needsRecovery($step) && app(Engine::class)->recover($step->id)) {
                $recovered++;
            }
        }

        return $recovered;
    }

    private function candidates(?string $workflowId): iterable
    {
        return StepRecord::whereHas('workflow', fn ($query) => $query->whereNull('finished_at')->whereNotNull('started_at'))
            ->when($workflowId !== null, fn ($query) => $query->where('workflow_id', $workflowId))->cursor();
    }

    private function needsRecovery(StepRecord $step): bool
    {
        if ($step->state::phase() === Phase::Running) {
            return $step->lease_until !== null && ! $step->lease_until->isFuture();
        }
        if ($step->state::phase() !== Phase::Queued || $step->available_at->isFuture()) {
            return false;
        }

        return DB::table('scarlett_deliveries')->where('step_id', $step->id)->where('attempt', $step->attempt)
            ->where(fn ($query) => $query->whereNull('publishing_until')->orWhere('publishing_until', '<=', now()))
            ->where(fn ($query) => $query->whereNull('sent_at')->orWhere('sent_at', '<=', now()->subSeconds((int) config('scarlett.delivery_retry_seconds'))))->exists();
    }
}
