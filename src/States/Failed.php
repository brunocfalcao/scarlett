<?php

declare(strict_types=1);

namespace Scarlett\States;

/**
 * Terminal failure that blocks successors and activates rescue and failure handling.
 */
class Failed extends StepState
{
    public static $name = 'failed';

    public static function phase(): Phase
    {
        return Phase::Settled;
    }

    public static function satisfiesDependency(): bool
    {
        return false;
    }

    public static function activatesRecovery(): bool
    {
        return true;
    }
}
