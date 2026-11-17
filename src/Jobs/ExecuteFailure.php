<?php

declare(strict_types=1);

/**
 * The queue message for one durable terminal failure-hook attempt.
 *
 * This is separate from an action job because the original step has already failed.
 * Release early deliveries until their saved due time, then let the failure runner
 * claim authority. Queue faults leave Coordinator and Recovery responsible for
 * retrying the saved hook attempt rather than replaying the original business action.
 */

namespace Scarlett\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Scarlett\Failures\Runner;
use Scarlett\Failures\Status;
use Scarlett\Models\FailureRecord;
use Throwable;

/**
 * A primitive transport envelope for a durable terminal-hook attempt.
 * Transport failures leave recovery to Scarlett rather than rerunning business work.
 */
final class ExecuteFailure implements ShouldQueue
{
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 0;

    public bool $failOnTimeout = true;

    public int $timeout;

    public function __construct(public string $failureId, public string $attempt, ?int $timeout = null)
    {
        $this->timeout = $timeout ?? (int) config('scarlett.worker_timeout');
    }

    public function handle(Runner $runner): void
    {
        try {
            $failure = FailureRecord::find($this->failureId);
            if ($failure !== null && $failure->attempt === $this->attempt && $failure->status === Status::Queued && $failure->available_at->isFuture()) {
                $this->release($failure->available_at);

                return;
            }
            $runner->run($this->failureId, $this->attempt);
        } catch (Throwable $exception) {
            $this->fail($exception);
            throw $exception;
        }
    }
}
