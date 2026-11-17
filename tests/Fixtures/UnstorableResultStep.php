<?php

declare(strict_types=1);

/**
 * Runs the observable parent action and returns an unsupported plain object.
 * This forces failure specifically at result persistence, after business execution,
 * so tests can verify the engine does not misclassify it as a verification retry.
 */

namespace Scarlett\Tests\Fixtures;

class UnstorableResultStep extends ControlledStep
{
    public function handle(): mixed
    {
        parent::handle();

        return new \stdClass;
    }
}
