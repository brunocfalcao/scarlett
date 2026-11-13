<?php

declare(strict_types=1);

namespace Scarlett\Tests\Fixtures;

use Scarlett\Execution\Context;
use Scarlett\Execution\Failure;

class InstanceFailureStep extends ControlledStep
{
    public function onFailure(Context $context, Failure $failure): void {}
}
