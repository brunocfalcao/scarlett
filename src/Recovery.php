<?php

declare(strict_types=1);

namespace Scarlett;

use Scarlett\Execution\Delivery;
use Scarlett\Models\StepRecord;
use Scarlett\States\Phase;
use Scarlett\States\StateRegistry;

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
        $states = collect(app(StateRegistry::class)->classes())
            ->filter(fn (string $class): bool => in_array($class::phase(), [Phase::Queued, Phase::Running], true))
            ->map(fn (string $class): string => $class::getMorphClass())->all();

        return StepRecord::whereIn('state', $states)
            ->whereHas('workflow', fn ($query) => $query->where('status', 'running'))
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

        return app(Delivery::class)->replayable($step)->exists();
    }
}
