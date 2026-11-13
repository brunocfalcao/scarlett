<?php

declare(strict_types=1);

namespace Scarlett\States;

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
