<?php

declare(strict_types=1);

namespace Scarlett\Tests\Fixtures;

use Scarlett\States\Phase;
use Scarlett\States\StepState;

class CustomFailedState extends StepState
{
    public static $name = 'customfailed';

    public static function phase(): Phase
    {
        return Phase::Settled;
    }

    public static function skipsChildren(): bool
    {
        return true;
    }

    public static function activatesRecovery(): bool
    {
        return true;
    }
}
