<?php

declare(strict_types=1);

/**
 * The parent action has finished, but its child work has not yet settled.
 * The parent must not release successors or activate recovery until that work is done.
 *
 * The stable alias is stored in the database. The methods below tell Progression
 * how to treat that alias, including whether it is final and whether work may follow.
 */

namespace Scarlett\States;

/**
 * The parent action is saved, but its subtree has not yet settled.
 */
class WaitingForChildren extends StepState
{
    public static $name = 'waitingforchildren';

    public static function phase(): Phase
    {
        return Phase::Children;
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
