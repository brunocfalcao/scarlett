<?php

declare(strict_types=1);

/**
 * A successful custom state whose stable alias tests replace before validating the registry.
 * The static alias is reset to null after each test that changes it.
 */

namespace Scarlett\Tests\Fixtures;

use Scarlett\States\Completed;

class AliasedState extends Completed
{
    public static $name = null;
}
