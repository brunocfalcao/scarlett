<?php

declare(strict_types=1);

/**
 * Exercises parameter shapes that named input preparation must preserve: optional
 * defaults, mixed, iterable, callable, literal booleans, and an intersection type.
 * The returned array exposes each argument after invocation for strict assertions.
 */

namespace Scarlett\Tests\Fixtures;

use Scarlett\Step;

class InvokerTypesStep extends Step
{
    public function __construct(
        public string $optionalConstructor = 'default-ctor',
    ) {}

    public function handle(
        mixed $anything,
        iterable $items,
        callable $callback,
        true $alwaysTrue,
        false $alwaysFalse,
        \Countable&\Traversable $intersection,
        int $optionalHandle = 99,
    ): array {
        return [
            $this->optionalConstructor,
            $anything,
            is_array($items) ? $items : iterator_to_array($items),
            $callback(),
            $alwaysTrue,
            $alwaysFalse,
            iterator_to_array($intersection),
            $optionalHandle,
        ];
    }
}
