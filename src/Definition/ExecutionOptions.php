<?php

declare(strict_types=1);

/**
 * Optional per-step overrides for timeout, lease, action retries, and verification.
 *
 * Null fields inherit package defaults. Resolving these values creates a complete
 * settings array that NodeStore saves with the step, so an active workflow does not
 * change its execution budget merely because configuration changes after deployment.
 * A Node-level options object replaces the class-level object rather than merging
 * with it; its remaining null fields therefore fall back to package configuration.
 */

namespace Scarlett\Definition;

use Scarlett\Configuration;

/** Overrides are resolved once at admission, so deployments do not change an active step's budget. */
final readonly class ExecutionOptions
{
    public function __construct(
        public ?int $workerTimeout = null,
        public ?int $leaseSeconds = null,
        public ?int $attempts = null,
        public ?array $backoffSeconds = null,
        public ?int $verificationAttempts = null,
        public ?array $verificationBackoffSeconds = null,
    ) {}

    public function snapshot(): array
    {
        /**
         * Resolve the complete values now instead of leaving configuration lookups to
         * future workers. Action and verification use separate counters and backoff lists,
         * so a verification problem cannot spend the action's remaining attempt budget.
         */
        $options = [
            'worker_timeout' => $this->workerTimeout ?? config('scarlett.worker_timeout'),
            'lease_seconds' => $this->leaseSeconds ?? config('scarlett.lease_seconds'),
            'retry' => [
                'attempts' => $this->attempts ?? config('scarlett.retry.attempts'),
                'backoff_seconds' => $this->backoffSeconds ?? config('scarlett.retry.backoff_seconds'),
            ],
            'verification' => [
                'attempts' => $this->verificationAttempts ?? config('scarlett.verification.attempts'),
                'backoff_seconds' => $this->verificationBackoffSeconds ?? config('scarlett.verification.backoff_seconds'),
            ],
        ];
        app(Configuration::class)->validateExecution($options);

        return $options;
    }
}
