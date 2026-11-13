<?php

declare(strict_types=1);

namespace Scarlett\Execution;

use Scarlett\Engine;
use Scarlett\Models\StepRecord;

final readonly class Context
{
    public function __construct(public string $workflowId, public string $stepId, public string $attempt, public int $failures) {}

    /** Database-only writes; returns false without invoking the callback after ownership is lost. */
    public function whileCurrent(callable $write): bool
    {
        return app(Engine::class)->whileCurrent($this->stepId, $this->attempt, $write);
    }

    /** Whether another business attempt remains after a failure in this attempt. */
    public function canRetry(): bool
    {
        $step = StepRecord::find($this->stepId);

        return $step !== null && $this->isCurrent()
            && app(config('scarlett.retry_policy'))->permits($step->nextFailureCount(), false, $step);
    }

    public function heartbeat(): bool
    {
        return app(Engine::class)->heartbeat($this->stepId, $this->attempt);
    }

    public function isCurrent(): bool
    {
        return app(Engine::class)->isCurrent($this->stepId, $this->attempt);
    }
}
