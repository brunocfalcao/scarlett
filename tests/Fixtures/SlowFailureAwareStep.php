<?php

declare(strict_types=1);

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
