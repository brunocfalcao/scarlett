<?php

declare(strict_types=1);

/**
 * Records lifecycle events while simulating the behaviors compared with dispatcher
 * semantics: precheck decisions, one retry, accepted exceptions, failure hooks, and
 * nested child discovery. Mode branches are test controls rather than engine rules;
 * exact event counts expose duplicate actions hidden by an otherwise correct final state.
 */

namespace Scarlett\Tests\Fixtures;

use RuntimeException;
use Scarlett\Definition\Node;
use Scarlett\Execution\Context;
use Scarlett\Execution\Decision;
use Scarlett\Execution\Failure;
use Scarlett\Step;
use Throwable;

class DispatcherParityStep extends Step
{
    public static array $events = [];

    public static int $hookCalls = 0;

    public function __construct(
        public string $name,
        public string $mode = 'value',
        public mixed $value = null,
        public int $spawnDepth = 0,
    ) {}

    public function before(Context $context): ?Decision
    {
        self::$events[] = ['before', $this->name, $this->mode];

        if ($this->mode === 'before-skip') {
            return Decision::skip();
        }

        if ($this->mode === 'before-stop') {
            return Decision::stop('Stopped in before');
        }

        if ($this->mode === 'before-fail') {
            return Decision::fail('Failed in before');
        }

        if ($this->mode === 'before-retry-once' && $context->failures === 0) {
            return Decision::retry(0);
        }

        return null;
    }

    public function handle(Context $context): mixed
    {
        self::$events[] = ['handle', $this->name, $this->mode];

        if ($this->mode === 'throw-resolve' || $this->mode === 'throw-children') {
            throw new RuntimeException("Recovered exception in {$this->name}");
        }

        if ($this->mode === 'fail') {
            throw new RuntimeException("Fatal error in {$this->name}");
        }

        /**
         * Each level uses a fresh local child plan and forwards its direct child's result.
         * This stresses nested ownership without introducing independent child workflows.
         */
        if ($this->mode === 'spawn' && $this->spawnDepth > 0) {
            return Decision::children([
                new Node('child-'.$this->spawnDepth, self::class, inputs: [
                    'name' => 'child-'.$this->spawnDepth,
                    'mode' => $this->spawnDepth > 1 ? 'spawn' : 'value',
                    'value' => $this->value,
                    'spawnDepth' => $this->spawnDepth - 1,
                ]),
            ], resultFrom: 'child-'.$this->spawnDepth);
        }

        return $this->value;
    }

    public function onException(Throwable $exception, Context $context): Decision
    {
        self::$events[] = ['onException', $this->name, $this->mode];

        if ($this->mode === 'throw-resolve') {
            return Decision::complete($this->value);
        }

        if ($this->mode === 'throw-children') {
            return Decision::children([
                new Node('fallback-leaf', EchoStep::class, inputs: ['value' => $this->value]),
            ], resultFrom: 'fallback-leaf');
        }

        return Decision::retry();
    }

    public function onFailure(Context $context, Failure $failure): void
    {
        self::$hookCalls++;
        self::$events[] = ['onFailure', $this->name, $failure->cause->value];
    }
}
