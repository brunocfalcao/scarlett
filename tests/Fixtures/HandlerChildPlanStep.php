<?php

declare(strict_types=1);

/**
 * Fails its action, then answers the exception with a child plan that admission rejects.
 * The failure hook makes Scarlett record the terminal cause, so a test can tell a rejected
 * classifier outcome apart from a result that could not be saved.
 */

namespace Scarlett\Tests\Fixtures;

use Scarlett\Definition\Node;
use Scarlett\Execution\Context;
use Scarlett\Execution\Decision;
use Scarlett\Execution\Failure;
use Scarlett\Step;
use Throwable;

final class HandlerChildPlanStep extends Step
{
    public static int $actions = 0;

    public static int $exceptions = 0;

    public function handle(): mixed
    {
        self::$actions++;

        throw new \RuntimeException('Action failed.');
    }

    public function onException(Throwable $exception, Context $context): Decision
    {
        self::$exceptions++;

        return Decision::children([
            new Node('same', ControlledStep::class, inputs: ['name' => 'same']),
            new Node('same', ControlledStep::class, inputs: ['name' => 'same']),
        ]);
    }

    public function onFailure(Context $context, Failure $failure): void {}
}
