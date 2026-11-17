<?php

declare(strict_types=1);

/**
 * Gives business code access to the authority of this specific execution attempt.
 *
 * The IDs describe the workflow and step, while attempt identifies the worker's
 * current right to act. isCurrent() is only a momentary read; whileCurrent() checks
 * again under locks and runs database writes atomically with that check. Failure
 * hooks have a separate failureId and route their authority checks to Coordinator,
 * because the original step is already finished when its terminal hook runs.
 */

namespace Scarlett\Execution;

use Scarlett\Engine;
use Scarlett\Failures\Coordinator;
use Scarlett\Models\StepRecord;

/**
 * An attempt-scoped capability for guarded writes, lease renewal and retry checks.
 * Failure handlers use their own attempt authority even though the step has settled.
 */
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

    /** Optional boundary after preparing inputs and before a long external call. */
    public function releaseDatabaseConnection(): bool
    {
        return app(ConnectionRelease::class)->release();
    }

    public function isCurrent(): bool
    {
        if ($this->failureId !== null) {
            return app(Coordinator::class)->isCurrent($this->failureId, $this->attempt);
        }

        return app(Engine::class)->isCurrent($this->stepId, $this->attempt);
    }
}
