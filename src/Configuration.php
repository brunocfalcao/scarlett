<?php

declare(strict_types=1);

namespace Scarlett;

use InvalidArgumentException;
use Scarlett\States\StateRegistry;

final class Configuration
{
    public function validate(): void
    {
        foreach (['lease_seconds', 'worker_timeout', 'delivery_retry_seconds', 'transaction_attempts'] as $name) {
            if (! is_int(config('scarlett.'.$name)) || config('scarlett.'.$name) < 1) {
                throw new InvalidArgumentException("scarlett.$name must be a positive integer.");
            }
        }
        if (config('scarlett.worker_timeout') >= config('scarlett.lease_seconds')) {
            throw new InvalidArgumentException('The worker timeout must be shorter than the execution lease.');
        }
        foreach (['retry', 'verification'] as $policy) {
            $attempts = config("scarlett.$policy.attempts");
            $backoff = config("scarlett.$policy.backoff_seconds");
            if (! is_int($attempts) || $attempts < 1 || ! is_array($backoff) || $backoff === [] || ! array_is_list($backoff)) {
                throw new InvalidArgumentException("Invalid Scarlett $policy policy.");
            }
            foreach ($backoff as $seconds) {
                if (! is_int($seconds) || $seconds < 0) {
                    throw new InvalidArgumentException('Backoff values must be non-negative integers.');
                }
            }
        }
        app(StateRegistry::class)->transitions();
    }
}
