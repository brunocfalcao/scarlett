<?php

declare(strict_types=1);

/**
 * Package defaults merged by ScarlettServiceProvider into the host configuration.
 *
 * Queue connection is Laravel's queue transport name, not a database connection.
 * Timeout bounds a worker job; lease bounds attempt authority and must be longer.
 * Action retries and result verification have independent budgets. Registry arrays
 * are opt-in application extensions; an empty array means no custom registrations.
 * Per-node execution overrides are validated and saved when work is admitted.
 */

use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Database\RecordsNotFoundException;
use Scarlett\Policies\InputExceptionHandler;
use Scarlett\Policies\RetryPolicy;

return [
    'queue' => env('SCARLETT_QUEUE', 'scarlett'),
    'connection' => env('SCARLETT_QUEUE_CONNECTION'),
    // Opt in only when the default database connection needs no session-local state.
    'release_database_connection' => env('SCARLETT_RELEASE_DATABASE_CONNECTION', false),
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
    /**
     * Preparation can fail before there is a step object to receive onException().
     * These classes classify that failure as terminal; the configured input handler
     * requests ordinary retries for other exceptions.
     */
    'input_terminal_exceptions' => [
        InvalidArgumentException::class,
        RecordsNotFoundException::class,
        BindingResolutionException::class,
        Error::class,
    ],
    /**
     * Custom state classes need stable aliases, coherent scheduling semantics, and
     * explicit allowed transitions. Payload aliases similarly decide which enum,
     * model, or mapper classes retained data is allowed to restore.
     */
    'states' => [],
    'transitions' => [],
    'payload_mappers' => [],
    'enums' => [],
    'models' => [],
];
