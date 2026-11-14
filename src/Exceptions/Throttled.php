<?php

declare(strict_types=1);

namespace Scarlett\Exceptions;

/**
 * Requests a non-negative wait from the action path without spending failure retries.
 */
class Throttled extends \RuntimeException
{
    public function __construct(public readonly int $seconds, string $message = 'Work temporarily throttled.')
    {
        if ($seconds < 0) {
            throw new \InvalidArgumentException('Throttle delay cannot be negative.');
        }
        parent::__construct($message);
    }
}
