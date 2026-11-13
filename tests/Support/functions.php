<?php

declare(strict_types=1);
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
    $step = StepRecord::where('workflow_id', $workflow)->where('key', $key)->sole();
    app(Runner::class)->run($step->id, $step->attempt ?? 'unclaimed');
}
