<?php

declare(strict_types=1);

/**
 * A worker has claimed this attempt and owns a time-limited lease.
 * Its action or verification is in progress, so dependent work must still wait.
 *
 * The stable alias is stored in the database. The methods below tell Progression
 * how to treat that alias, including whether it is final and whether work may follow.
 */

namespace Scarlett\States;

/**
 * A claimed attempt whose lease must remain current before guarded mutations.
 */
class Running extends StepState
{
    public static $name = 'running';

    public static function phase(): Phase
    {
        return Phase::Running;
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
