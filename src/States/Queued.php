<?php

declare(strict_types=1);

namespace Scarlett\States;

/**
 * An eligible attempt with a durable delivery intent; it may still have a future due time.
 */
class Queued extends StepState
{
    public static $name = 'queued';

    public static function phase(): Phase
    {
        return Phase::Queued;
    }

    public static function satisfiesDependency(): bool
    {
        return false;
    }

    public static function activatesRecovery(): bool
    {
        return false;
    }
}
