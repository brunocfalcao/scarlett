<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Queue;
use Scarlett\Configuration;
use Scarlett\Definition\Node;
use Scarlett\Definition\Workflow;
use Scarlett\Engine;
use Scarlett\Inputs\PayloadCodec;
use Scarlett\States\Running;
use Scarlett\States\WaitingForChildren;
use Scarlett\Tests\Fixtures\AcceptingStep;
use Scarlett\Tests\Fixtures\AmountMapper;
use Scarlett\Tests\Fixtures\Approved;
use Scarlett\Tests\Fixtures\ApproveStep;

it('keeps accepted exceptions behind mandatory verification and preserves zero results', function () {
    Queue::fake();
    $this->freezeSecond();
    AcceptingStep::$actions = 0;
    AcceptingStep::$verified = false;
    $flow = app(Engine::class)->start(new Workflow([new Node('accepted', AcceptingStep::class), controlled('after', stage: 1)]));
    runStep($flow->id, 'accepted');
    expect(statesOf($flow->id))->toBe(['accepted' => 'queued', 'after' => 'pending']);
    expect(AcceptingStep::$actions)->toBe(1);
    AcceptingStep::$verified = true;
    $this->travel(5)->seconds();
    runStep($flow->id, 'accepted');
    expect(statesOf($flow->id))->toBe(['accepted' => 'completed', 'after' => 'queued']);
    expect(AcceptingStep::$actions)->toBe(1);
    expect(app(PayloadCodec::class)->decode($flow->steps()->where('key', 'accepted')->sole()->result))->toBe(0);
});
it('uses a custom completion state after its descendants conclude', function () {
    Queue::fake();
    config(['scarlett.payload_mappers' => ['amount' => AmountMapper::class], 'scarlett.states' => [Approved::class],
        'scarlett.transitions' => [[Running::class, Approved::class], [WaitingForChildren::class, Approved::class]]]);
    $flow = app(Engine::class)->start(new Workflow([new Node('parent', ApproveStep::class), controlled('child', parent: 'parent')]));
    runStep($flow->id, 'parent');
    expect(statesOf($flow->id))->toBe(['child' => 'queued', 'parent' => 'waitingforchildren']);
    runStep($flow->id, 'child');
    expect(statesOf($flow->id))->toBe(['child' => 'completed', 'parent' => 'approved']);
    expect($flow->fresh()->status)->toBe('completed');
});
it('rejects unsafe timing and unknown state transitions before work starts', function () {
    config(['scarlett.worker_timeout' => config('scarlett.lease_seconds')]);
    expect(fn () => app(Configuration::class)->validate())->toThrow(InvalidArgumentException::class, 'timeout');
    config(['scarlett.worker_timeout' => 10, 'scarlett.transitions' => [[Running::class, 'UnknownState']]]);
    expect(fn () => app(Configuration::class)->validate())->toThrow(InvalidArgumentException::class, 'registered states');
});
it('keeps ordered recovery stages behind all parallel recovery outcomes', function () {
    Queue::fake();
    $flow = app(Engine::class)->start(new Workflow([controlled('failed', 'fail'), controlled('r1', rescues: 'failed'), controlled('r2', rescues: 'failed'), controlled('join', stage: 1, rescues: 'failed')]));
    runStep($flow->id, 'failed');
    runStep($flow->id, 'r1');
    expect(statesOf($flow->id))->toBe(['failed' => 'failed', 'join' => 'pending', 'r1' => 'completed', 'r2' => 'queued']);
    runStep($flow->id, 'r2');
    expect(statesOf($flow->id)['join'])->toBe('queued');
});
