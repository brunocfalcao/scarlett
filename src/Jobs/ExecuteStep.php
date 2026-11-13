<?php

declare(strict_types=1);

namespace Scarlett\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Scarlett\Execution\Runner;
use Scarlett\Models\StepRecord;
use Scarlett\States\Phase;

final class ExecuteStep implements ShouldQueue
{
    use InteractsWithQueue;
    use Queueable;

    // Early transport releases do not consume Scarlett's business retry budget.
    public int $tries = 0;

    public bool $failOnTimeout = true;

    public int $timeout;

    public function __construct(public string $stepId, public string $attempt, ?int $workerTimeout = null)
    {
        $this->timeout = $workerTimeout ?? (int) config('scarlett.worker_timeout');
    }

    public function handle(Runner $runner): void
    {
        try {
            $step = StepRecord::find($this->stepId);
            if ($step !== null && $step->attempt === $this->attempt && $step->state::phase() === Phase::Queued && $step->available_at->isFuture()) {
                $this->release($step->available_at);

                return;
            }
            $runner->run($this->stepId, $this->attempt);
        } catch (\Throwable $exception) {
            // Scarlett owns recovery; a transport fault must not start an independent retry loop.
            $this->fail($exception);

            throw $exception;
        }
    }
}
