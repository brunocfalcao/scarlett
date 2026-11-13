<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Queue;
use Scarlett\Definition\ExecutionOptions;
use Scarlett\Definition\Node;
use Scarlett\Definition\Workflow;
use Scarlett\Engine;
use Scarlett\Tests\Fixtures\ControlledStep;
use Scarlett\Tests\Fixtures\DefaultOptionsStep;

it('snapshots class defaults without constructing future steps and lets node options replace them', function () {
    Queue::fake();
    ControlledStep::$constructed = [];
    $flow = app(Engine::class)->start(new Workflow([
        new Node('class', DefaultOptionsStep::class, inputs: ['name' => 'class']),
        new Node('node', DefaultOptionsStep::class, stage: 1, inputs: ['name' => 'node'], execution: new ExecutionOptions(attempts: 5)),
    ]));
    expect(ControlledStep::$constructed)->toBe([]);
    $class = $flow->steps()->where('key', 'class')->sole();
    $node = $flow->steps()->where('key', 'node')->sole();
    // Normalize object member order only; preserve scalar types and ordered backoff lists.
    $snapshot = $class->execution;
    foreach (['retry', 'verification'] as $policy) {
        ksort($snapshot[$policy]);
    }
    ksort($snapshot);
    expect($snapshot)->toBe(['lease_seconds' => 60, 'retry' => ['attempts' => 2, 'backoff_seconds' => [7]], 'verification' => ['attempts' => 4, 'backoff_seconds' => [11]], 'worker_timeout' => 40]);
    expect($node->executionOptions()['retry']['attempts'])->toBe(5)
        ->and($node->executionOptions()['worker_timeout'])->toBe(config('scarlett.worker_timeout'));
    config(['scarlett.worker_timeout' => 10]);
    expect($class->fresh()->executionOptions()['worker_timeout'])->toBe(40);
    runStep($flow->id, 'class');
    expect(ControlledStep::$constructed)->toBe([['class', null]]);
    runStep($flow->id, 'node');
    expect($flow->fresh()->status)->toBe('completed');
});
