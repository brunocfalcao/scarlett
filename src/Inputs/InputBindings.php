<?php

declare(strict_types=1);

/**
 * Saves the recipe for each named input, then obtains its value when needed.
 *
 * A binding connects a constructor or handle() parameter name to a literal value,
 * a service lookup, or a prerequisite's result. Admission stores only the recipe.
 * Execution resolves it after claiming the attempt, so future results need not exist
 * when the workflow is declared and services are not frozen into stored objects.
 */

namespace Scarlett\Inputs;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Collection;
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
            /**
             * A result reference cannot create its own ordering edge. Without an existing
             * prerequisite, this step could run before the source and try to read an absent
             * result; reject the declaration instead of introducing a timing-dependent failure.
             */
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
        $sources = $this->resultSources($step);
        $inputs = [];
        foreach ($step->inputs as $name => $binding) {
            $inputs[$name] = match ($binding['kind']) {
                'result' => $this->result($sources, $binding['step']),
                'service' => app($binding['class']),
                'literal' => $this->codec->decode($binding['payload']),
                default => throw new InvalidArgumentException('Unknown input binding.'),
            };
        }

        return $inputs;
    }

    /**
     * Read every prerequisite result this step names in one query. A missing row still
     * fails at its own binding, with the same exception type as a single-row lookup.
     *
     * @return Collection<array-key, StepRecord>
     */
    private function resultSources(StepRecord $step): Collection
    {
        $keys = array_values(array_unique(array_column(
            array_filter($step->inputs, fn (array $binding): bool => $binding['kind'] === 'result'),
            'step',
        )));
        if ($keys === []) {
            return new Collection;
        }

        return StepRecord::where('workflow_id', $step->workflow_id)
            ->whereIn('key', $keys)
            ->get(['key', 'has_result', 'result'])
            ->keyBy('key');
    }

    /** @param Collection<array-key, StepRecord> $sources */
    private function result(Collection $sources, string $key): mixed
    {
        $source = $sources->get($key) ?? throw (new ModelNotFoundException)->setModel(StepRecord::class);
        /**
         * A saved null is a legitimate result, so test the explicit has_result flag.
         * Skipped or cancelled steps can have no action result at all; that is different
         * from a step that ran successfully and returned null.
         */
        if (! $source->has_result) {
            throw new InvalidArgumentException("Step $key did not produce a result.");
        }

        return $this->codec->decode($source->result);
    }
}
