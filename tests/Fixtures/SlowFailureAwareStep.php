<?php

declare(strict_types=1);

/**
 * Sleeps in either the business action or terminal hook long enough for tests to
 * exercise real worker timeout and recovery. These waits model interrupted execution;
 * the parent fixture supplies observable hook effects after successful recovery.
 */

namespace Scarlett\Tests\Fixtures;

use Scarlett\Execution\Context;
use Scarlett\Execution\Decision;
use Scarlett\Execution\Failure;

class SlowFailureAwareStep extends FailureAwareStep
{
    public function before(Context $context): ?Decision
    {
        return $this->mode === 'slow-hook' ? Decision::fail('Hook timeout scenario.') : null;
    }

    public function handle(): mixed
    {
        sleep(3);

        return null;
    }

    public function onFailure(Context $context, Failure $failure): void
    {
        if ($this->mode === 'slow-hook') {
            sleep(3);
        }
        parent::onFailure($context, $failure);
    }
}
