<?php

declare(strict_types=1);

/**
 * Lets action code request postponement without classifying it as a failed attempt.
 *
 * Runner recognizes this exception before calling the step's normal exception hook.
 * The non-negative seconds become a throttle decision; zero still receives the
 * configured minimum wait. It does not mean the normal retry budget was exhausted.
 */

namespace Scarlett\Exceptions;

/**
 * Requests a non-negative wait from the action path without spending failure retries.
 */
class Throttled extends \RuntimeException
{
    public function __construct(public readonly int $seconds, string $message = 'Work temporarily throttled.', int $code = 0, ?\Throwable $previous = null)
    {
        if ($seconds < 0) {
            throw new \InvalidArgumentException('Throttle delay cannot be negative.');
        }
        parent::__construct($message, $code, $previous);
    }
}
