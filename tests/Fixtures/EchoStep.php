<?php

declare(strict_types=1);

/**
 * Returns its supplied value unchanged, including null.
 * This removes business logic from ordering and payload tests: any changed value or
 * missing result comes from engine preparation or persistence rather than this action.
 */

namespace Scarlett\Tests\Fixtures;

use Scarlett\Step;

class EchoStep extends Step
{
    public function handle(mixed $value = null): mixed
    {
        return $value;
    }
}
