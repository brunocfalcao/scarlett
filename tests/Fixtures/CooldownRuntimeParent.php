<?php

declare(strict_types=1);

namespace Scarlett\Tests\Fixtures;

use Scarlett\Engine;
use Scarlett\Execution\Context;
use Scarlett\Models\StepRecord;

final class CooldownRuntimeParent extends DispatcherParityStep
{
    public function handle(Context $context): mixed
    {
        self::$events[] = ['cooldown', $this->name, (string) StepRecord::findOrFail($context->stepId)->state];
        app(Engine::class)->cooldown();

        return parent::handle($context);
    }
}
