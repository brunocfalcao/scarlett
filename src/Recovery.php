<?php

declare(strict_types=1);

/**
 * Finds interrupted work that normal outcome-driven progression cannot advance.
 *
 * A queued attempt may have lost its queue delivery; a running worker may have died
 * and allowed its lease to expire. Include both action and terminal failure-hook
 * attempts. Scans only suggest candidates: Engine or Coordinator rereads under locks
 * before repair. Healthy work does not need a periodic tick from this class.
 */

namespace Scarlett;

use Scarlett\Execution\Delivery;
use Scarlett\Failures\Coordinator;
use Scarlett\Models\FailureRecord;
use Scarlett\Models\StepRecord;
use Scarlett\States\Phase;
use Scarlett\States\StateRegistry;

/**
 * Repairs lost deliveries and expired leases, independently of healthy progression.
 * Candidate batches close their read cursors before mutation acquires the workflow lock.
 * Reads are advisory; mutation rechecks eligibility under that lock.
 */
final class Recovery
{
    public function stalled(?string $workflowId = null): bool
    {
        foreach ($this->candidates($workflowId) as $step) {
            if ($this->needsRecovery($step)) {
                return true;
            }
        }

        foreach ($this->failureCandidates($workflowId) as $failure) {
            if (app(Coordinator::class)->needsRecovery($failure)) {
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

        foreach ($this->failureCandidates($workflowId) as $failure) {
            if (app(Coordinator::class)->needsRecovery($failure) && app(Coordinator::class)->recover($failure->id)) {
                $recovered++;
            }
        }

        return $recovered;
    }

    private function failureCandidates(?string $workflowId): iterable
    {
        return FailureRecord::unfinished()->when($workflowId !== null, fn ($query) => $query->where('workflow_id', $workflowId))->lazyById(100);
    }

    private function candidates(?string $workflowId): iterable
    {
        /**
         * Use registered state semantics instead of hard-coding state aliases. Custom
         * states in queued/running phases must be eligible too. Held workflows are excluded
         * because their work has not been authorized to start yet.
         */
        $states = collect(app(StateRegistry::class)->classes())
            ->filter(fn (string $class): bool => in_array($class::phase(), [Phase::Queued, Phase::Running], true))
            ->map(fn (string $class): string => $class::getMorphClass())->all();

        return StepRecord::whereIn('state', $states)
            ->whereHas('workflow', fn ($query) => $query->where('status', 'running'))
            ->when($workflowId !== null, fn ($query) => $query->where('workflow_id', $workflowId))->lazyById(100);
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
