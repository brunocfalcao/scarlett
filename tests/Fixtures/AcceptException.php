<?php

declare(strict_types=1);

/**
 * Returns an accepted zero result from exception classification. Zero is deliberately
 * falsey: the engine must preserve the supplied value and still verify it as success.
 */

namespace Scarlett\Tests\Fixtures;

use Scarlett\Execution\Context;
use Scarlett\Execution\Decision;
use Scarlett\Policies\ExceptionHandler;

final class AcceptException extends ExceptionHandler
{
    public function decide(\Throwable $exception, Context $context): Decision
    {
        return Decision::complete(0);
    }
}
