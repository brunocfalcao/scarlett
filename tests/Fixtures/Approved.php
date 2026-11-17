<?php

declare(strict_types=1);

/**
 * A custom successful state with a stable alias. Inheriting Completed keeps its
 * dependency and recovery semantics, while tests must explicitly register transitions
 * into it to prove custom success is not automatically reachable.
 */

namespace Scarlett\Tests\Fixtures;

use Scarlett\States\Completed;

class Approved extends Completed
{
    public static $name = 'approved';
}
