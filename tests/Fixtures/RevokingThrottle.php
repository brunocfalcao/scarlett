<?php

declare(strict_types=1);

namespace Scarlett\Tests\Fixtures;

use Scarlett\Engine;
use Scarlett\Execution\Context;
use Scarlett\Execution\Decision;
use Scarlett\Policies\Throttle;

final class RevokingThrottle extends Throttle
{
    public function delay(Context $context): ?int
    {
        app(Engine::class)->decide($context->stepId, $context->attempt, Decision::stop());

        return null;
    }
}
