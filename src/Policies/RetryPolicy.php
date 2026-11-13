<?php

declare(strict_types=1);

namespace Scarlett\Policies;

use Scarlett\Models\StepRecord;

class RetryPolicy
{
    public function permits(int $failures, bool $verification = false, ?StepRecord $step = null): bool
    {
        $policy = $this->settings($step, $verification);

        return $failures < $policy['attempts'];
    }

    public function delay(int $failures, bool $verification = false, ?StepRecord $step = null): int
    {
        $schedule = $this->settings($step, $verification)['backoff_seconds'];
        if (! is_array($schedule) || $schedule === []) {
            throw new \InvalidArgumentException('Retry backoff needs a non-empty list.');
        }

        return max(0, (int) $schedule[min(max(0, $failures - 1), count($schedule) - 1)]);
    }

    private function settings(?StepRecord $step, bool $verification): array
    {
        $name = $verification ? 'verification' : 'retry';

        return $step?->executionOptions()[$name] ?? config('scarlett.'.$name);
    }
}
