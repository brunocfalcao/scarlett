<?php

declare(strict_types=1);

/**
 * Execution authority was withdrawn rather than business failure being accepted.
 * Successors cannot proceed, but rescues are not activated solely by cancellation.
 * A stale worker cannot regain permission merely by keeping its old token.
 *
 * The stable alias is stored in the database. The methods below tell Progression
 * how to treat that alias, including whether it is final and whether work may follow.
 */

namespace Scarlett\States;

/**
 * Withdrawn authority: blocks successors without activating failure recovery.
 */
class Cancelled extends StepState
{
    public static $name = 'cancelled';

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
        return false;
    }
}
