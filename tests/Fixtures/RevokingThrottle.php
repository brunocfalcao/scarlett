<?php

declare(strict_types=1);

/**
 * Revokes the running attempt through an engine stop decision, then reports no wait.
 * Returning null must not let Runner proceed: the policy call changed ownership,
 * so the mandatory authority recheck must stop the old attempt first.
 */

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
