<?php

declare(strict_types=1);

namespace Scarlett\Execution;

use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Scarlett\Definition\Workflow;
use Scarlett\Inputs\InputBindings;
use Scarlett\Models\StepRecord;
use Scarlett\States\Pending;

/** Persists a validated graph inside the caller's workflow transaction. */
final class NodeStore
{
    public function __construct(private InputBindings $bindings) {}

    public function store(string $workflowId, Workflow $definition): void
    {
        foreach ($definition->nodes as $node) {
            $dependencies = $definition->dependencies($node);
            StepRecord::create([
                'id' => (string) Str::uuid(),
                'workflow_id' => $workflowId,
                'key' => $node->key,
                'class' => $node->step,
                'state' => Pending::class,
                'dependencies' => $dependencies,
                'inputs' => $this->bindings->encode($node->inputs, array_merge($dependencies, array_filter([$node->parent, $node->rescues], fn (?string $key): bool => $key !== null))),
                'parent_key' => $node->parent,
                'rescues_key' => $node->rescues,
                'execution' => $node->executionOptions()->snapshot(),
                'not_before' => $node->notBefore === null ? null : Carbon::instance($node->notBefore)->setTimezone(config('app.timezone')),
                'priority' => $node->priority ?? 0,
                'queue' => $node->queue ?? config('scarlett.queue'),
                'connection' => $node->connection ?? config('scarlett.connection'),
            ]);
        }
    }
}
