<?php

declare(strict_types=1);

namespace Scarlett\Definition;

use InvalidArgumentException;
use ReflectionClass;
use Scarlett\Step;

final readonly class Workflow
{
    /** @param list<Node> $nodes */
    public function __construct(public array $nodes) {}

    public function validate(): void
    {
        if ($this->nodes === []) {
            throw new InvalidArgumentException('A workflow needs at least one step.');
        }
        $indexed = [];
        foreach ($this->nodes as $node) {
            if (! $node instanceof Node || $node->key === '') {
                throw new InvalidArgumentException('Each step needs a non-empty identity.');
            }
            if (isset($indexed[$node->key])) {
                throw new InvalidArgumentException("Duplicate step identity: {$node->key}");
            }
            if (! is_subclass_of($node->step, Step::class) || ! (new ReflectionClass($node->step))->isInstantiable() || ! method_exists($node->step, 'handle')) {
                throw new InvalidArgumentException("{$node->step} must be a concrete Scarlett step with a handle method.");
            }
            if ($node->parent !== null && $node->rescues !== null) {
                throw new InvalidArgumentException('A recovery root cannot also have a parent.');
            }
            $this->validateSignature($node);
            $indexed[$node->key] = $node;
        }
        foreach ($this->nodes as $node) {
            $seen = [$node->key => true];
            $ancestor = $node->parent ?? $node->rescues;
            while ($ancestor !== null) {
                if (isset($seen[$ancestor])) {
                    throw new InvalidArgumentException('Cycle in workflow ancestry.');
                }
                if (! isset($indexed[$ancestor])) {
                    throw new InvalidArgumentException("Unknown ancestor: {$ancestor}");
                }
                $seen[$ancestor] = true;
                $ancestor = $indexed[$ancestor]->parent ?? $indexed[$ancestor]->rescues;
            }
        }
    }

    private function validateSignature(Node $node): void
    {
        $reflection = new ReflectionClass($node->step);
        $handler = $reflection->getMethod('handle');
        if (! $handler->isPublic() || $handler->isStatic()) {
            throw new InvalidArgumentException('The handle method must be public and non-static.');
        }
        $known = [];
        foreach (array_filter([$reflection->getConstructor(), $handler]) as $method) {
            foreach ($method->getParameters() as $parameter) {
                $name = $parameter->getName();
                $known[] = $name;
                if ($parameter->isPassedByReference()) {
                    throw new InvalidArgumentException("Input $name cannot be passed by reference.");
                }
                if (! array_key_exists($name, $node->inputs) && ! $parameter->isOptional()) {
                    throw new InvalidArgumentException("Missing input $name for {$node->key}.");
                }
            }
        }
        foreach (array_keys($node->inputs) as $name) {
            if (! in_array($name, $known, true)) {
                throw new InvalidArgumentException("Unknown input $name for {$node->key}.");
            }
        }
    }

    /** All earlier siblings are prerequisites; equal-stage siblings run in parallel. */
    public function dependencies(Node $node): array
    {
        return array_values(array_map(fn (Node $other): string => $other->key, array_filter(
            $this->nodes,
            fn (Node $other): bool => $other->parent === $node->parent && $other->rescues === $node->rescues && $other->stage < $node->stage,
        )));
    }
}
