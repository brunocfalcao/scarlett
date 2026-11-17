<?php

declare(strict_types=1);

/**
 * Demonstrates the basic scheduling contract with minimal business behavior.
 *
 * A queue fake exposes which stage is eligible before execution. A separate synchronous
 * case lets committed outcomes immediately run a sequence without a scheduler tick.
 * The false result is deliberate: readiness and result presence cannot use truthiness.
 */

use Illuminate\Support\Facades\Queue;
use Scarlett\Definition\Node;
use Scarlett\Definition\Workflow;
use Scarlett\Engine;
use Scarlett\Inputs\FromResult;
use Scarlett\Inputs\PayloadCodec;
use Scarlett\Tests\Fixtures\EchoStep;

it('queues only the first parallel stage without constructing later work', function () {
    Queue::fake();
    $workflow = app(Engine::class)->start(new Workflow([
        new Node('first', EchoStep::class), new Node('peer', EchoStep::class),
        new Node('later', EchoStep::class, stage: 1),
    ]));
    expect(statesOf($workflow->id))->toBe(['first' => 'queued', 'later' => 'pending', 'peer' => 'queued']);
});
it('runs a sequence from committed results without a polling tick', function () {
    $workflow = app(Engine::class)->start(new Workflow([
        new Node('first', EchoStep::class, inputs: ['value' => false]),
        new Node('later', EchoStep::class, stage: 1, inputs: ['value' => new FromResult('first')]),
    ]));
    expect(statesOf($workflow->id))->toBe(['first' => 'completed', 'later' => 'completed']);
    expect($workflow->fresh()->status)->toBe('completed');
    expect(app(PayloadCodec::class)->decode($workflow->steps()->where('key', 'later')->sole()->result))->toBeFalse();
});
