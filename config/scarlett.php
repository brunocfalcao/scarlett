<?php

declare(strict_types=1);

use Scarlett\Policies\RetryPolicy;

return [
    'queue' => env('SCARLETT_QUEUE', 'scarlett'),
    'connection' => env('SCARLETT_QUEUE_CONNECTION'),
    // Worker timeout must be shorter than the lease; transport retry_after must exceed timeout.
    'lease_seconds' => 300,
    'worker_timeout' => 240,
    'delivery_retry_seconds' => 30,
    'transaction_attempts' => 5,
    'retry' => ['attempts' => 3, 'backoff_seconds' => [5, 30, 120]],
    'verification' => ['attempts' => 3, 'backoff_seconds' => [5, 30, 120]],
    'retry_policy' => RetryPolicy::class,
    'states' => [],
    'transitions' => [],
    'payload_mappers' => [],
    'enums' => [],
    'models' => [],
];
