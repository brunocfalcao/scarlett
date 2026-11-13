<?php

declare(strict_types=1);

namespace Scarlett\Tests\Fixtures;

use Scarlett\States\Completed;
use Scarlett\States\Phase;

class MeasuredState extends Completed
{
    public static $name = 'measured';

    public static int $phaseReads = 0;

    public static function phase(): Phase
    {
        self::$phaseReads++;

        return Phase::Settled;
    }
}
