<?php

declare(strict_types=1);

/**
 * The step reached terminal failure after a decision, exhausted budget, or lost work.
 * Later ordinary stages cannot proceed. Rescue branches and any opted-in durable
 * failure hook may now run; rescue success does not rewrite this original state.
 *
 * The stable alias is stored in the database. The methods below tell Progression
 * how to treat that alias, including whether it is final and whether work may follow.
 */

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
