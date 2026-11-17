<?php

declare(strict_types=1);

/**
 * Selects a named quota and the identity that should share it.
 *
 * For example, different steps can use the same provider policy and account key to
 * share one rolling request window. The name loads limits from configuration; the
 * key chooses which calls compete. Hash both into one exact database identity, then
 * let ThrottleReservations lock and allocate capacity for the current action attempt.
 */

namespace Scarlett\Policies;

use InvalidArgumentException;
use Scarlett\Execution\Context;
use Scarlett\Execution\ThrottleReservations;

/**
 * Selects a named rolling-window quota and an explicit sharing key.
 * This controls action admission; failed calls still use the step's retry policy.
 */
final class RateLimit extends Throttle
{
    public readonly int $maxRequests;

    public readonly int $perSeconds;

    public function __construct(public readonly string $name, public readonly string $key)
    {
        $policies = config('scarlett.throttles', []);
        $policy = is_array($policies) ? ($policies[$name] ?? null) : null;
        if ($name === '' || $key === '' || ! is_array($policy)
            || ! is_int($policy['max_requests'] ?? null) || $policy['max_requests'] < 1
            || ! is_int($policy['per_seconds'] ?? null) || $policy['per_seconds'] < 1
            || $policy['per_seconds'] > intdiv(PHP_INT_MAX - now()->getTimestampMs(), ThrottleReservations::MILLISECONDS_PER_SECOND)) {
            throw new InvalidArgumentException('A named throttle requires a non-empty sharing key, positive integer max_requests and representable positive per_seconds.');
        }
        $this->maxRequests = $policy['max_requests'];
        $this->perSeconds = $policy['per_seconds'];
    }

    /** Exact hashed identity avoids collation ambiguity and storing account identifiers. */
    public function id(): string
    {
        return hash('sha256', json_encode([$this->name, $this->key], JSON_THROW_ON_ERROR));
    }

    public function delay(Context $context): ?int
    {
        return app(ThrottleReservations::class)->delay($this, $context);
    }
}
