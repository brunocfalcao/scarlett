<?php

declare(strict_types=1);

/**
 * Small shared vocabulary for tests that manually drive workflow deliveries.
 *
 * statesOf() returns a deterministic key-to-state map, controlled() creates a Node
 * using the observable ControlledStep fixture, and runStep() delivers the current
 * saved attempt to the real runner. These helpers do not bypass engine claims or
 * create results directly; queue-faked cases still exercise the execution lifecycle.
 */
use Scarlett\Definition\Node;
use Scarlett\Execution\Runner;
use Scarlett\Models\StepRecord;
use Scarlett\Tests\Fixtures\ControlledStep;

function statesOf(string $workflow): array
{
    return StepRecord::where('workflow_id', $workflow)->orderBy('key')->get()->mapWithKeys(fn ($step) => [$step->key => (string) $step->state])->all();
}
function controlled(string $key, string $mode = 'success', int $stage = 0, ?string $parent = null, ?string $rescues = null): Node
{
    return new Node($key, ControlledStep::class, stage: $stage, inputs: ['name' => $key, 'mode' => $mode], parent: $parent, rescues: $rescues);
}
function runStep(string $workflow, string $key): void
{
    /**
     * Read the current token on each delivery so the helper follows genuine retries.
     * The unclaimed fallback deliberately cannot acquire authority for a pending step.
     */
    $step = StepRecord::where('workflow_id', $workflow)->where('key', $key)->sole();
    app(Runner::class)->run($step->id, $step->attempt ?? 'unclaimed');
}
