<?php

declare(strict_types=1);

/**
 * A successful custom state that counts calls to phase().
 * Efficiency tests observe traversal work directly while preserving ordinary successful
 * scheduling semantics; a correct final result alone would not reveal repeated scans.
 */

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
