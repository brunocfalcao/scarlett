<?php

declare(strict_types=1);

/**
 * A custom final state that requests recovery and skips unused children.
 * Tests register its alias to prove the engine reads state semantics rather than
 * assuming every recovery-triggering state has the built-in Failed class name.
 */

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
