<?php

declare(strict_types=1);

/**
 * Returns runtime child plans that engine admission either accepts or rejects.
 * Counters reveal whether a rejected or unsaved handoff replays the business action
 * or its exception classifier. Modes choose a valid handle() plan, an invalid one, an
 * invalid one whose rejection text mentions a deadlock, or a before() throttle that reaches
 * the engine before any action runs.
 */

namespace Scarlett\Tests\Fixtures;

use Scarlett\Definition\Node;
use Scarlett\Execution\Context;
use Scarlett\Execution\Decision;
use Scarlett\Step;
use Throwable;

final class InvalidChildPlanStep extends Step
{
    public static int $actions = 0;

    public static int $exceptions = 0;

    public function __construct(public string $mode = 'invalid') {}

    public function before(Context $context): ?Decision
    {
        return $this->mode === 'throttle' ? Decision::throttle(5) : null;
    }

    public function handle(): mixed
    {
        self::$actions++;
        // A rejection whose text reads like a database error must still settle as a rejection.
        $key = $this->mode === 'deadlock-named' ? 'deadlock detected' : 'same';

        return match ($this->mode) {
            'valid' => Decision::children([new Node('child', ControlledStep::class, inputs: ['name' => 'child'])]),
            default => Decision::children([
                new Node($key, ControlledStep::class, inputs: ['name' => $key]),
                new Node($key, ControlledStep::class, inputs: ['name' => $key]),
            ]),
        };
    }

    public function onException(Throwable $exception, Context $context): Decision
    {
        self::$exceptions++;

        return Decision::retry();
    }
}
