<?php

declare(strict_types=1);

namespace Scarlett\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Scarlett\Failures\Runner;
use Scarlett\Failures\Status;
use Scarlett\Models\FailureRecord;
use Throwable;

final class ExecuteFailure implements ShouldQueue
{
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 0;
    public bool $failOnTimeout = true;

    public function __construct(public string $failureId, public string $attempt, public int $timeout) {}

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
