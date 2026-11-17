<?php

declare(strict_types=1);

/**
 * The step exists but is waiting for its parent, earlier stages, or workflow start.
 * It has no execution authority yet and neither releases successors nor starts rescues.
 *
 * The stable alias is stored in the database. The methods below tell Progression
 * how to treat that alias, including whether it is final and whether work may follow.
 */

namespace Scarlett\States;

/**
 * Admitted definition awaiting prerequisites, parent readiness or admission reopening.
 */
class Pending extends StepState
{
    public static $name = 'pending';

    public static function phase(): Phase
    {
        return Phase::Pending;
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
