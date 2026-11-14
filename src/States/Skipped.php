<?php

declare(strict_types=1);

namespace Scarlett\States;

/**
 * Intentional no-op: satisfies sibling ordering but supplies no action result.
 * Unstarted descendants are skipped with their parent.
 */
class Skipped extends StepState
{
    public static $name = 'skipped';

    public static function skipsChildren(): bool
    {
        return true;
    }

    public static function phase(): Phase
    {
        return Phase::Settled;
    }

    public static function satisfiesDependency(): bool
    {
        return true;
    }

    public static function activatesRecovery(): bool
    {
        return false;
    }
}
