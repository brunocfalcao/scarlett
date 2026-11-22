<?php

declare(strict_types=1);

/**
 * Decides whether another attempt is allowed and how long it should wait.
 *
 * Configured attempts is the total allowed count including the first try. After one
 * failure, a budget of three therefore allows two more tries. Action and verification
 * have separate settings. Prefer the values saved on the step; configuration is a
 * fallback when no step is supplied. Reuse the last backoff for longer retry budgets.
 */

namespace Scarlett\Policies;

use Scarlett\Models\StepRecord;

/**
 * Applies the admitted budget, counting the initial attempt in the total.
 * After the last configured backoff entry, later permitted retries reuse that delay.
 */
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
        if (! is_array($schedule) || $schedule === [] || ! array_is_list($schedule)) {
            throw new \InvalidArgumentException('Retry backoff needs a non-empty list.');
        }

        /**
         * Failure counts start at one; array positions start at zero. Clamp to the last
         * entry so a longer allowed budget reuses its final wait instead of reading past
         * the configured list. The returned delay is never negative.
         */
        return max(0, (int) $schedule[min(max(0, $failures - 1), count($schedule) - 1)]);
    }

    private function settings(?StepRecord $step, bool $verification): array
    {
        $name = $verification ? 'verification' : 'retry';

        return $step?->executionOptions()[$name] ?? config('scarlett.'.$name);
    }
}
