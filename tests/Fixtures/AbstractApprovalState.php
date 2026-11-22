<?php

declare(strict_types=1);

/**
 * An abstract successful state. It inherits a phase, so only its abstractness makes it
 * unusable: Spatie cannot construct it when a model is hydrated or transitions into it.
 */

namespace Scarlett\Tests\Fixtures;

use Scarlett\States\Completed;

abstract class AbstractApprovalState extends Completed
{
    public static $name = 'abstract-approval';
}
