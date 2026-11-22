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
        if (! mb_check_encoding($name, 'UTF-8') || ! mb_check_encoding($key, 'UTF-8')) {
            throw new InvalidArgumentException('A named throttle requires valid UTF-8 names and sharing keys.');
        }
        $policies = config('scarlett.throttles', []);
        $policy = is_array($policies) ? ($policies[$name] ?? null) : null;
        if ($name === '' || $key === '' || ! self::isValidPolicy($policy)) {
            throw new InvalidArgumentException('A named throttle requires a non-empty sharing key, positive integer max_requests and representable positive per_seconds.');
        }
        $this->maxRequests = $policy['max_requests'];
        $this->perSeconds = $policy['per_seconds'];
    }

    /**
     * Shared by construction and Configuration::validate(), so both accept exactly the same
     * policy shapes. The per_seconds ceiling keeps the window in milliseconds representable
     * when the quota is later checked; ThrottleReservations rechecks it at that time.
     */
    public static function isValidPolicy(mixed $policy): bool
    {
        return is_array($policy)
            && is_int($policy['max_requests'] ?? null) && $policy['max_requests'] >= 1
            && is_int($policy['per_seconds'] ?? null) && $policy['per_seconds'] >= 1
            && $policy['per_seconds'] <= intdiv(PHP_INT_MAX - now()->getTimestampMs(), ThrottleReservations::MILLISECONDS_PER_SECOND);
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
