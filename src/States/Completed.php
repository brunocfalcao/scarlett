<?php

declare(strict_types=1);

/**
 * The step has an accepted outcome and releases later sibling stages.
 * This is final successful work, so rescue branches are not needed.
 *
 * The stable alias is stored in the database. The methods below tell Progression
 * how to treat that alias, including whether it is final and whether work may follow.
 */

namespace Scarlett\States;

/**
 * Successful settlement that releases dependent siblings.
 */
class Completed extends StepState
{
    public static $name = 'completed';

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
