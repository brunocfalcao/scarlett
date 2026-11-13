<?php

declare(strict_types=1);

namespace Scarlett\States;

class Skipped extends StepState
{
    public static $name = 'skipped';

    public static function skipsChildren(): bool
    {
        return true;
    }

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
