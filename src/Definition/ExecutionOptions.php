<?php

declare(strict_types=1);

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
