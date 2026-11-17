<?php

declare(strict_types=1);

/**
 * Executes an injected callback during construction. Tests use this boundary to
 * change authority while inputs are being prepared, proving the runner checks again
 * before allowing the successfully constructed object to execute its action.
 */

namespace Scarlett\Tests\Fixtures;

use Closure;
use Scarlett\Step;

class PreparingStep extends Step
{
    public function __construct(Closure $prepare)
    {
        $prepare();
    }

    public function handle(): int
    {
        return 42;
    }
}
