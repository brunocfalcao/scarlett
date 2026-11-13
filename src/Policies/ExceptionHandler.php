<?php

declare(strict_types=1);

namespace Scarlett\Policies;

use Scarlett\Execution\Context;
use Scarlett\Execution\Decision;

abstract class ExceptionHandler
{
    abstract public function decide(\Throwable $exception, Context $context): Decision;
}
