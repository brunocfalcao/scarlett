<?php

declare(strict_types=1);

namespace Scarlett\States;

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
