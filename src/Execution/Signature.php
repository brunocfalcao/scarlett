<?php

declare(strict_types=1);

namespace Scarlett\Execution;

use Closure;
use ReflectionClass;
use ReflectionMethod;
use RuntimeException;
use Scarlett\Inputs\PayloadCodec;
use Scarlett\Models\StepRecord;
use Throwable;

/** Describes declarations without constructing steps, then records the arguments actually passed. */
final class Signature
{
    public function __construct(private PayloadCodec $codec) {}

    public static function describe(string $class): array
    {
        $reflection = new ReflectionClass($class);
        $signature = [];
        foreach (['constructor' => $reflection->getConstructor(), 'handle' => $reflection->getMethod('handle')] as $name => $method) {
            $parameters = [];
            foreach ($method?->getParameters() ?? [] as $parameter) {
                $parameters[$parameter->getName()] = [
                    'position' => $parameter->getPosition(),
                    'type' => $parameter->hasType() ? (string) $parameter->getType() : null,
                    'optional' => $parameter->isOptional(),
                    'variadic' => $parameter->isVariadic(),
                    'context' => Invoker::isContext($parameter),
                ];
            }
            $signature[$name] = ['parameters' => $parameters, 'return_type' => $method?->hasReturnType() ? (string) $method->getReturnType() : null];
        }

        return $signature;
    }

    /** Recording never serializes service internals or requires a new mapper for existing defaults. */
    public function recorder(StepRecord $record, Context $context): Closure
    {
        return function (?ReflectionMethod $method, array $arguments) use ($record, $context): void {
            $parameters = [];
            $offset = 0;
            foreach ($method?->getParameters() ?? [] as $parameter) {
                $name = $parameter->getName();
                $value = $parameter->isVariadic() ? array_slice($arguments, $offset) : $arguments[$offset];
                $offset++;
                $binding = $record->inputs[$name] ?? null;
                $parameters[$name] = match (true) {
                    Invoker::isContext($parameter) => ['kind' => 'context'],
                    ($binding['kind'] ?? null) === 'service' => ['kind' => 'service', 'class' => $binding['class']],
                    default => $this->value($value),
                };
            }
            $phase = $method?->getName() === 'handle' ? 'handle' : 'constructor';
            $saved = $context->whileCurrent(function () use ($context, $phase, $parameters): void {
                $current = StepRecord::findOrFail($context->stepId);
                if ($current->action_completed) {
                    return;
                }
                $snapshot = $current->arguments;
                if (($snapshot['attempt'] ?? null) !== $context->attempt) {
                    $snapshot = ['attempt' => $context->attempt];
                }
                $snapshot[$phase] = $parameters;
                $current->update(['arguments' => $snapshot]);
            });
            if (! $saved) {
                throw new RuntimeException('Attempt lost ownership before invocation.');
            }
            if ($phase === 'handle') {
                $context->releaseDatabaseConnection();
            }
        };
    }

    private function value(mixed $value): array
    {
        try {
            $payload = $this->codec->encode($value);
            json_encode($payload, JSON_THROW_ON_ERROR);

            return ['kind' => 'value', 'payload' => $payload];
        } catch (Throwable) {
            return ['kind' => 'unavailable', 'type' => get_debug_type($value)];
        }
    }
}
