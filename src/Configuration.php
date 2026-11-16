<?php

declare(strict_types=1);

namespace Scarlett;

use BackedEnum;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use Scarlett\Contracts\PayloadMapper;
use Scarlett\Definition\ExecutionOptions;
use Scarlett\Execution\ThrottleReservations;
use Scarlett\Policies\ExceptionHandler;
use Scarlett\Policies\RetryPolicy;
use Scarlett\States\StateRegistry;

/**
 * Rejects invalid execution contracts before any workflow is admitted.
 * Timeout and lease ordering must hold for defaults and every node snapshot.
 */
final class Configuration
{
    public function validate(): void
    {
        foreach (['lease_seconds', 'worker_timeout', 'delivery_retry_seconds', 'transaction_attempts', 'minimum_throttle_seconds'] as $name) {
            if (! is_int(config('scarlett.'.$name)) || config('scarlett.'.$name) < 1) {
                throw new InvalidArgumentException("scarlett.$name must be a positive integer.");
            }
        }
        (new ExecutionOptions)->snapshot();
        app(StateRegistry::class)->transitions();
        $handler = config('scarlett.input_exception_handler');
        if (! is_string($handler) || ! is_subclass_of($handler, ExceptionHandler::class)) {
            throw new InvalidArgumentException('scarlett.input_exception_handler must extend ExceptionHandler.');
        }
        $terminalExceptions = config('scarlett.input_terminal_exceptions');
        if (! is_array($terminalExceptions) || ! array_is_list($terminalExceptions)) {
            throw new InvalidArgumentException('scarlett.input_terminal_exceptions must be a list of exception classes.');
        }
        foreach ($terminalExceptions as $exception) {
            if (! is_string($exception) || ! is_a($exception, \Throwable::class, true)) {
                throw new InvalidArgumentException('scarlett.input_terminal_exceptions entries must implement Throwable.');
            }
        }
        foreach (config('scarlett.payload_mappers', []) as $mapper) {
            if (! is_string($mapper) || ! is_subclass_of($mapper, PayloadMapper::class)) {
                throw new InvalidArgumentException('scarlett.payload_mappers entries must implement PayloadMapper.');
            }
        }
        $retryPolicy = config('scarlett.retry_policy');
        if (! is_string($retryPolicy) || ! is_a($retryPolicy, RetryPolicy::class, true)) {
            throw new InvalidArgumentException('scarlett.retry_policy must extend RetryPolicy.');
        }
        $this->validateClassAliases('enums', BackedEnum::class, 'BackedEnum');
        $this->validateClassAliases('models', Model::class, 'Eloquent models');
        $throttles = config('scarlett.throttles', []);
        if (! is_array($throttles)) {
            throw new InvalidArgumentException('scarlett.throttles must be an array.');
        }
        foreach ($throttles as $name => $policy) {
            if ((! is_string($name) && ! is_int($name)) || $name === '' || ! is_array($policy)
                || ! is_int($policy['max_requests'] ?? null) || $policy['max_requests'] < 1
                || ! is_int($policy['per_seconds'] ?? null) || $policy['per_seconds'] < 1
                || $policy['per_seconds'] > intdiv(PHP_INT_MAX - now()->getTimestampMs(), ThrottleReservations::MILLISECONDS_PER_SECOND)) {
                throw new InvalidArgumentException('scarlett.throttles entries require a non-empty name and positive integer max_requests and per_seconds.');
            }
        }
    }

    private function validateClassAliases(string $registry, string $baseClass, string $label): void
    {
        $aliases = config("scarlett.$registry", []);
        if (! is_array($aliases)) {
            throw new InvalidArgumentException("scarlett.$registry must be an array.");
        }
        foreach ($aliases as $class) {
            if (! is_string($class) || ! is_subclass_of($class, $baseClass, true)) {
                throw new InvalidArgumentException("scarlett.$registry entries must reference $label classes.");
            }
        }
    }

    public function validateExecution(array $options): void
    {
        foreach (['worker_timeout', 'lease_seconds'] as $name) {
            if (! is_int($options[$name]) || $options[$name] < 1) {
                throw new InvalidArgumentException("$name must be a positive integer.");
            }
        }
        if ($options['worker_timeout'] >= $options['lease_seconds']) {
            throw new InvalidArgumentException('The worker timeout must be shorter than the execution lease.');
        }
        foreach (['retry', 'verification'] as $policy) {
            $attempts = $options[$policy]['attempts'];
            $backoff = $options[$policy]['backoff_seconds'];
            if (! is_int($attempts) || $attempts < 1 || ! is_array($backoff) || $backoff === [] || ! array_is_list($backoff)) {
                throw new InvalidArgumentException("Invalid Scarlett $policy policy.");
            }
            foreach ($backoff as $seconds) {
                if (! is_int($seconds) || $seconds < 0) {
                    throw new InvalidArgumentException('Backoff values must be non-negative integers.');
                }
            }
        }
    }
}
