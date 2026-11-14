<?php

declare(strict_types=1);

namespace Scarlett\Inputs;

use InvalidArgumentException;
use Scarlett\Models\StepRecord;

/**
 * Stores binding recipes at admission and resolves live values only at execution.
 * Result references must be prerequisites; the binding itself adds no ordering edge.
 */
final class InputBindings
{
    public function __construct(private PayloadCodec $codec) {}

    public function encode(array $inputs, array $prerequisites): array
    {
        $encoded = [];
        foreach ($inputs as $name => $input) {
            if (! is_string($name)) {
                throw new InvalidArgumentException('Inputs must have parameter names.');
            }
            if ($input instanceof FromResult && ! in_array($input->step, $prerequisites, true)) {
                throw new InvalidArgumentException("Result {$input->step} must come from a prerequisite.");
            }
            $encoded[$name] = match (true) {
                $input instanceof FromResult => ['kind' => 'result', 'step' => $input->step],
                $input instanceof Service => ['kind' => 'service', 'class' => $input->class],
                default => ['kind' => 'literal', 'payload' => $this->codec->encode($input)],
            };
        }

        return $encoded;
    }

    public function resolve(StepRecord $step): array
    {
        $inputs = [];
        foreach ($step->inputs as $name => $binding) {
            $inputs[$name] = match ($binding['kind']) {
                'result' => $this->result($step, $binding['step']),
                'service' => app($binding['class']),
                'literal' => $this->codec->decode($binding['payload']),
                default => throw new InvalidArgumentException('Unknown input binding.'),
            };
        }

        return $inputs;
    }

    private function result(StepRecord $step, string $key): mixed
    {
        $source = $step->workflow->steps()->where('key', $key)->sole();
        if (! $source->has_result) {
            throw new InvalidArgumentException("Step $key did not produce a result.");
        }

        return $this->codec->decode($source->result);
    }
}
