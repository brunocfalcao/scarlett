<?php

declare(strict_types=1);

namespace Scarlett\States;

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
