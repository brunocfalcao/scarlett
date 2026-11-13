<?php

declare(strict_types=1);

namespace Scarlett\Execution;

use InvalidArgumentException;
use ReflectionClass;
use ReflectionFunctionAbstract;
use ReflectionMethod;
use Scarlett\Step;

final class Invoker
{
    public function construct(string $class, array $inputs): Step
    {
        $reflection = new ReflectionClass($class);
        $constructor = $reflection->getConstructor();

        return new $class(...($constructor ? $this->arguments($constructor, $inputs) : []));
    }

    public function handle(Step $step, array $inputs): mixed
    {
        $method = new ReflectionMethod($step, 'handle');

        return $step->handle(...$this->arguments($method, $inputs));
    }

    private function arguments(ReflectionFunctionAbstract $method, array $inputs): array
    {
        $arguments = [];
        foreach ($method->getParameters() as $parameter) {
            $name = $parameter->getName();
            if ($parameter->isVariadic()) {
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
