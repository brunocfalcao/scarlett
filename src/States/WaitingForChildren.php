<?php

declare(strict_types=1);

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
