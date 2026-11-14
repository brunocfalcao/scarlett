<?php

declare(strict_types=1);

use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Database\RecordsNotFoundException;
use Scarlett\Policies\InputExceptionHandler;
use Scarlett\Policies\RetryPolicy;

return [
    'queue' => env('SCARLETT_QUEUE', 'scarlett'),
    'connection' => env('SCARLETT_QUEUE_CONNECTION'),
    // Worker timeout must be shorter than the lease; transport retry_after must exceed timeout.
    'lease_seconds' => 300,
    'worker_timeout' => 240,
    'delivery_retry_seconds' => 30,
    'transaction_attempts' => 5,
    // A zero throttle requests this wait; explicit positive delays remain unchanged.
    'minimum_throttle_seconds' => 1,
    // Named rolling-window quotas; steps explicitly choose a policy and sharing key.
    'throttles' => [],
    'retry' => ['attempts' => 3, 'backoff_seconds' => [5, 30, 120]],
    'verification' => ['attempts' => 3, 'backoff_seconds' => [5, 30, 120]],
    'retry_policy' => RetryPolicy::class,
    'input_exception_handler' => InputExceptionHandler::class,
    'input_terminal_exceptions' => [
        InvalidArgumentException::class,
        RecordsNotFoundException::class,
        BindingResolutionException::class,
        Error::class,
    ],
    'states' => [],
    'transitions' => [],
    'payload_mappers' => [],
    'enums' => [],
    'models' => [],
];
