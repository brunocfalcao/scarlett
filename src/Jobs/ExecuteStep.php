<?php

declare(strict_types=1);

namespace Scarlett\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Scarlett\Execution\Runner;

final class ExecuteStep implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout;

    public function __construct(public string $stepId, public string $attempt)
    {
        $this->timeout = (int) config('scarlett.worker_timeout');
    }

    public function handle(Runner $runner): void
    {
        $runner->run($this->stepId, $this->attempt);
    }
}
