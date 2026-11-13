<?php

declare(strict_types=1);

namespace Scarlett;

use InvalidArgumentException;
use Scarlett\Contracts\PayloadMapper;
use Scarlett\Definition\ExecutionOptions;
use Scarlett\Policies\ExceptionHandler;
use Scarlett\States\StateRegistry;

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
