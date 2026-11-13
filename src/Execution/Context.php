<?php

declare(strict_types=1);

namespace Scarlett\Execution;

use Scarlett\Engine;
use Scarlett\Failures\Coordinator;
use Scarlett\Models\StepRecord;

final readonly class Context
{
    public function __construct(public string $workflowId, public string $stepId, public string $attempt, public int $failures, public ?string $failureId = null) {}

    /** Database-only writes; returns false without invoking the callback after ownership is lost. */
    public function whileCurrent(callable $write): bool
    {
        if ($this->failureId !== null) {
            return app(Coordinator::class)->whileCurrent($this->failureId, $this->attempt, $write);
        }

        return app(Engine::class)->whileCurrent($this->stepId, $this->attempt, $write);
    }

    /** Whether another action or failure-handler attempt remains after a failure in this attempt. */
    public function canRetry(): bool
    {
        if ($this->failureId !== null) {
            return app(Coordinator::class)->canRetry($this->failureId, $this->attempt);
        }

        $step = StepRecord::find($this->stepId);

        return $step !== null && $this->isCurrent()
            && app(config('scarlett.retry_policy'))->permits($step->nextFailureCount(), false, $step);
    }

    public function heartbeat(): bool
    {
        if ($this->failureId !== null) {
            return app(Coordinator::class)->heartbeat($this->failureId, $this->attempt);
        }

        return app(Engine::class)->heartbeat($this->stepId, $this->attempt);
    }

    public function isCurrent(): bool
    {
        if ($this->failureId !== null) {
            return app(Coordinator::class)->isCurrent($this->failureId, $this->attempt);
        }

        return app(Engine::class)->isCurrent($this->stepId, $this->attempt);
    }
}
