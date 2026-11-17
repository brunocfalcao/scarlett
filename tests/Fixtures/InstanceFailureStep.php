<?php

declare(strict_types=1);

/**
 * Overrides the instance terminal hook with a no-op.
 * The override itself opts the step into durable failure registration, allowing
 * tests to distinguish hook presence from any particular business effect.
 */

namespace Scarlett\Tests\Fixtures;

use Scarlett\Execution\Context;
use Scarlett\Execution\Failure;

class InstanceFailureStep extends ControlledStep
{
    public function onFailure(Context $context, Failure $failure): void {}
}
