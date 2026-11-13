<?php

declare(strict_types=1);

namespace Scarlett\Tests\Fixtures;

class UnstorableResultStep extends ControlledStep
{
    public function handle(): mixed
    {
        parent::handle();

        return new \stdClass;
    }
}
