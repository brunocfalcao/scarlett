<?php

declare(strict_types=1);

namespace Scarlett\Execution;

use InvalidArgumentException;
use ReflectionClass;
use ReflectionFunctionAbstract;
use ReflectionIntersectionType;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionParameter;
use ReflectionType;
use ReflectionUnionType;
use Scarlett\Step;

final class Invoker
{
    public function construct(string $class, array $inputs, ?Context $context = null): Step
    {
        $reflection = new ReflectionClass($class);
        $constructor = $reflection->getConstructor();

        return new $class(...($constructor ? $this->arguments($constructor, $inputs, $context) : []));
    }

    public function handle(Step $step, array $inputs, ?Context $context = null): mixed
    {
        $method = new ReflectionMethod($step, 'handle');

        return $step->handle(...$this->arguments($method, $inputs, $context));
    }

    public static function isContext(ReflectionParameter $parameter): bool
    {
        $type = $parameter->getType();

        return $type instanceof ReflectionNamedType && $type->getName() === Context::class;
    }

    /** Check only literal shapes whose restored type is already known, without resolving bindings or mappers. */
    public static function validateLiteral(ReflectionParameter $parameter, mixed $value): void
    {
        if (is_object($value)) {
            return;
        }
        if ($parameter->isVariadic() && (! is_array($value) || ! array_is_list($value))) {
            throw new InvalidArgumentException("Variadic input {$parameter->getName()} requires a list.");
        }
        foreach ($parameter->isVariadic() ? $value : [$value] as $literal) {
            if (! is_object($literal) && ! self::acceptsLiteral($parameter->getType(), $literal)) {
                throw new InvalidArgumentException("Input {$parameter->getName()} does not match {$parameter->getType()}.");
            }
        }
    }

    private static function acceptsLiteral(?ReflectionType $type, mixed $value): bool
    {
        if ($type === null || ($value === null && $type->allowsNull())) {
            return true;
        }
        if ($type instanceof ReflectionUnionType) {
            return collect($type->getTypes())->contains(fn ($part): bool => self::acceptsLiteral($part, $value));
        }
        if ($type instanceof ReflectionIntersectionType) {
            return collect($type->getTypes())->every(fn ($part): bool => self::acceptsLiteral($part, $value));
        }

        return match ($type->getName()) {
            'mixed' => true,
            'int' => is_int($value),
            'float' => is_float($value) || is_int($value),
            'string' => is_string($value),
            'bool' => is_bool($value),
            'true' => $value === true,
            'false' => $value === false,
            'array', 'iterable' => is_array($value),
            'callable' => is_callable($value),
            default => false,
        };
    }

    private function arguments(ReflectionFunctionAbstract $method, array $inputs, ?Context $context): array
    {
        $arguments = [];
        foreach ($method->getParameters() as $parameter) {
            $name = $parameter->getName();
            if (self::isContext($parameter)) {
                $arguments[] = $context ?? throw new InvalidArgumentException('Execution context is unavailable.');
            } elseif ($parameter->isVariadic()) {
                $values = $inputs[$name] ?? [];
                if (! is_array($values) || ! array_is_list($values)) {
                    throw new InvalidArgumentException("Variadic input $name requires a list.");
                }
                array_push($arguments, ...$values);
            } elseif (array_key_exists($name, $inputs)) {
                $arguments[] = $inputs[$name];
            } elseif ($parameter->isDefaultValueAvailable()) {
                $arguments[] = $parameter->getDefaultValue();
            } else {
                throw new InvalidArgumentException("Missing explicit input: $name");
            }
        }

        return $arguments;
    }
}
