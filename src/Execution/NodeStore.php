<?php

declare(strict_types=1);

/**
 * Turns a checked workflow declaration into the records workers will later execute.
 *
 * Node objects describe work; StepRecord rows retain its durable execution state.
 * Store input recipes rather than resolving their values now: a previous step may
 * not have produced its result yet, and a service should be looked up when used.
 * The caller's transaction makes saving the whole graph an all-or-nothing operation.
 */

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
        // Homogeneous fanouts share one step class; describe each class once per store call.
        $signatures = [];
        /**
         * A node's prerequisites depend only on its parent link, rescue link and stage, so
         * siblings that share all three share one list. Computing it once per sibling stage
         * keeps a wide fanout from rescanning every node for every step it stores.
         */
        $stageDependencies = [];
        foreach ($definition->nodes as $node) {
            /**
             * Stage prerequisites control scheduling. Parent and rescue references also allow
             * reading an owner's result, so pass those additional keys to input encoding without
             * adding them to the sibling-stage list. Save settings now so deployment changes
             * to defaults do not rewrite an active step's retry or timeout budget.
             */
            $dependencies = $stageDependencies[json_encode([$node->parent, $node->rescues, $node->stage], JSON_THROW_ON_ERROR)]
                ??= $definition->dependencies($node);
            StepRecord::create([
                'id' => (string) Str::uuid(),
                'workflow_id' => $workflowId,
                'key' => $node->key,
                'class' => $node->step,
                'title' => $node->title,
                'description' => $node->description,
                'canonical' => $node->canonical,
                'signature' => $signatures[$node->step] ??= Signature::describe($node->step),
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
