<?php

declare(strict_types=1);

/**
 * The step intentionally did no action but still allows later stages to proceed.
 * It has no action result to read. Its unstarted children are skipped too, and no
 * rescue is activated; this differs from both success with a null result and cancellation.
 *
 * The stable alias is stored in the database. The methods below tell Progression
 * how to treat that alias, including whether it is final and whether work may follow.
 */

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
