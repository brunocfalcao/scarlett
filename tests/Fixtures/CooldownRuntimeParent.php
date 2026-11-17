<?php

declare(strict_types=1);

/**
 * Enables admission cooldown from inside an already-running parent before creating
 * its child plan. Recorded events prove cooldown holds unrelated new workflows while
 * the started parent and its nested children remain allowed to finish.
 */

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
