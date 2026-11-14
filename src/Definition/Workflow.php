<?php

declare(strict_types=1);

namespace Scarlett\Definition;

use InvalidArgumentException;
use ReflectionClass;
use Scarlett\Execution\Invoker;
use Scarlett\Step;

/**
 * Validates the declared graph and signatures without constructing business steps.
 * Dependencies include all earlier stages in the same parent/rescue group.
 */
final readonly class Workflow
{
    /** @param list<Node> $nodes */
    public function __construct(
        public array $nodes,
        public ?string $subjectType = null,
        public ?string $subjectId = null,
        public array $metadata = [],
        public ?string $title = null,
        public ?string $description = null,
        public ?string $canonical = null,
    ) {}

    public function validate(): void
    {
        if ($this->nodes === []) {
            throw new InvalidArgumentException('A workflow needs at least one step.');
        }
        if (($this->subjectType === null) !== ($this->subjectId === null)) {
            throw new InvalidArgumentException('A workflow subject requires both its type and its identifier.');
        }
        foreach ([$this->subjectType, $this->subjectId] as $value) {
            if ($value !== null && $value === '') {
                throw new InvalidArgumentException('Workflow subject values must not be empty.');
            }
        }
        json_encode($this->metadata, JSON_THROW_ON_ERROR);
        $this->validateMetadata($this->metadata);
        $indexed = [];
        foreach ($this->nodes as $node) {
            if (! $node instanceof Node || $node->key === '') {
                throw new InvalidArgumentException('Each step needs a non-empty identity.');
            }
            if (mb_strlen($node->key, 'UTF-8') > Node::MAX_KEY_LENGTH) {
                throw new InvalidArgumentException('Step identity exceeds the storage length limit.');
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
            $node->executionOptions()->snapshot();
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

    private function validateMetadata(array $values): void
    {
        foreach ($values as $value) {
            if (is_array($value)) {
                $this->validateMetadata($value);
            } elseif ($value !== null && ! is_scalar($value)) {
                throw new InvalidArgumentException('Workflow metadata accepts JSON values only.');
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
                if (Invoker::isContext($parameter)) {
                    if (array_key_exists($name, $node->inputs) || $parameter->isVariadic()) {
                        throw new InvalidArgumentException('Execution context is supplied by the engine and cannot be bound or variadic.');
                    }

                    continue;
                }
                if (! array_key_exists($name, $node->inputs) && ! $parameter->isOptional()) {
                    throw new InvalidArgumentException("Missing input $name for {$node->key}.");
                }
                if (array_key_exists($name, $node->inputs)) {
                    Invoker::validateLiteral($parameter, $node->inputs[$name]);
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
