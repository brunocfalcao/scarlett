<?php

declare(strict_types=1);

namespace Scarlett\States;

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
