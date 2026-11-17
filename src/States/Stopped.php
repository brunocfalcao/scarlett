<?php

declare(strict_types=1);

/**
 * Business code deliberately ended this step unsuccessfully.
 * Its recovery behavior matches failure: successors are blocked and rescues may run,
 * while the distinct alias preserves that the stop was intentional.
 *
 * The stable alias is stored in the database. The methods below tell Progression
 * how to treat that alias, including whether it is final and whether work may follow.
 */

namespace Scarlett\States;

/**
 * Intentional terminal stop with the same recovery semantics as failure.
 */
class Stopped extends StepState
{
    public static $name = 'stopped';

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
