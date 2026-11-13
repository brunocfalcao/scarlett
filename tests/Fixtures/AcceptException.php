<?php

declare(strict_types=1);

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
