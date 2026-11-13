<?php

declare(strict_types=1);

namespace Scarlett\Policies;

class RetryPolicy
{
    public function permits(int $failures, bool $verification = false): bool
    {
        return $failures < max(1, (int) config('scarlett.'.($verification ? 'verification' : 'retry').'.attempts'));
    }

    public function delay(int $failures, bool $verification = false): int
    {
        $schedule = config('scarlett.'.($verification ? 'verification' : 'retry').'.backoff_seconds');
        if (! is_array($schedule) || $schedule === []) {
            throw new \InvalidArgumentException('Retry backoff needs a non-empty list.');
        }

        return max(0, (int) $schedule[min(max(0, $failures - 1), count($schedule) - 1)]);
    }
}
