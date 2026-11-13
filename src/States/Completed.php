<?php

declare(strict_types=1);

namespace Scarlett\States;

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
