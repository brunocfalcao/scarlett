<?php

declare(strict_types=1);

/**
 * Pins extension and construction contracts that application code can depend on.
 *
 * Manual and container construction must both work. Accepted exceptions still pass
 * verification, custom successful states still require legal transitions, and parent
 * completion errors belong to the parent rather than its last child. Intermediate
 * state and action-count assertions expose replay that a final status could hide.
 */

use Illuminate\Support\Facades\Queue;
use Scarlett\Configuration;
use Scarlett\Definition\Node;
use Scarlett\Definition\Workflow;
use Scarlett\Engine;
use Scarlett\Execution\Decision;
use Scarlett\Execution\Delivery;
use Scarlett\Execution\Journal;
use Scarlett\Execution\Progression;
use Scarlett\Failures\Coordinator;
use Scarlett\Failures\Status;
use Scarlett\Inputs\PayloadCodec;
use Scarlett\Jobs\ExecuteFailure;
use Scarlett\Jobs\ExecuteStep;
use Scarlett\States\Running;
use Scarlett\States\Skipped;
use Scarlett\States\StateRegistry;
use Scarlett\States\WaitingForChildren;
use Scarlett\Tests\Fixtures\AbstractApprovalState;
use Scarlett\Tests\Fixtures\AcceptingStep;
use Scarlett\Tests\Fixtures\AliasedState;
use Scarlett\Tests\Fixtures\AmountMapper;
use Scarlett\Tests\Fixtures\Approved;
use Scarlett\Tests\Fixtures\ApproveStep;
use Scarlett\Tests\Fixtures\CompletionStep;
use Scarlett\Tests\Fixtures\CustomAlertTransition;
use Scarlett\Tests\Fixtures\FailureAwareStep;
use Scarlett\Tests\Fixtures\GuardedApproval;

it('preserves published construction while coordinating action and failure attempts', function (string $construction) {
    Queue::fake();
    $journal = app(Journal::class);
    $delivery = $construction === 'step delivery' ? new Delivery : app(Delivery::class);
    $failureDelivery = $construction === 'failure delivery' ? new Scarlett\Failures\Delivery : app(Scarlett\Failures\Delivery::class);
    $coordinator = $construction === 'coordinator' ? new Coordinator : app(Coordinator::class);
    $engine = $construction === 'engine'
        ? new Engine(app(PayloadCodec::class), new Progression($journal), $journal, $delivery)
        : app(Engine::class);
    $flow = $engine->start(new Workflow([
        new Node('action', FailureAwareStep::class, inputs: ['name' => 'compatibility']),
    ]));
    $step = $flow->steps()->sole();
    expect((string) $step->state)->toBe('queued');
    expect($engine->claim($step->id, $step->attempt)?->id)->toBe($step->id);
    expect($engine->decide($step->id, $step->attempt, Decision::fail('compatibility')))->toBeTrue();
    $failure = $flow->failures()->sole();
    expect($failure->status)->toBe(Status::Queued);
    $failureDelivery->flush($flow->id);
    $delivery->flush($flow->id);
    expect($coordinator->claim($failure->id, $failure->attempt)?->id)->toBe($failure->id)
        ->and($coordinator->complete($failure->id, $failure->attempt))->toBeTrue()
        ->and($flow->fresh()->status)->toBe('failed');
    Queue::assertPushed(ExecuteStep::class, 1);
    Queue::assertPushed(ExecuteFailure::class, 1);
})->with(['container control', 'engine', 'coordinator', 'step delivery', 'failure delivery']);

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

it('attributes an unreachable completion state to the choosing step', function (bool $parent) {
    Queue::fake();
    config(['scarlett.payload_mappers' => ['amount' => AmountMapper::class], 'scarlett.states' => [Approved::class],
        'scarlett.transitions' => $parent ? [[Running::class, Approved::class]] : []]);
    $nodes = [new Node('owner', ApproveStep::class)];
    if ($parent) {
        $nodes[] = controlled('child', parent: 'owner');
    }
    $flow = app(Engine::class)->start(new Workflow($nodes));
    expect(statesOf($flow->id)['owner'])->toBe('queued');
    runStep($flow->id, 'owner');
    $owner = $flow->steps()->where('key', 'owner')->sole();
    expect((string) $owner->state)->toBe('failed')->and($owner->error)->toContain('scarlett.transitions');
    if ($parent) {
        expect(statesOf($flow->id)['child'])->toBe('cancelled');
    }
})->with([false, true]);

it('rejects result-producing completion with skip semantics', function () {
    Queue::fake();
    $flow = app(Engine::class)->start(new Workflow([
        new Node('invalid', CompletionStep::class, inputs: ['state' => Skipped::class, 'value' => 'kept']),
        controlled('child', parent: 'invalid'), controlled('skip', 'skip'),
    ]));
    runStep($flow->id, 'invalid');
    runStep($flow->id, 'skip');
    expect(statesOf($flow->id))->toBe(['child' => 'cancelled', 'invalid' => 'failed', 'skip' => 'skipped']);
    expect($flow->steps()->where('key', 'invalid')->sole()->has_result)->toBeFalse();
    expect($flow->steps()->where('key', 'skip')->sole()->has_result)->toBeFalse();
});

it('rejects mapper configuration mistakes before admitting scalar-only workflows', function () {
    config(['scarlett.payload_mappers' => ['invalid' => stdClass::class]]);
    expect(fn () => app(Engine::class)->start(new Workflow([controlled('scalar')])))->toThrow(InvalidArgumentException::class, 'scarlett.payload_mappers');
});

it('attributes denied completion guards to their owner without rolling back children', function (bool $parent, bool $allowed) {
    Queue::fake();
    GuardedApproval::$allowed = $allowed;
    config(['scarlett.payload_mappers' => ['amount' => AmountMapper::class], 'scarlett.states' => [Approved::class],
        'scarlett.transitions' => [
            [Running::class, Approved::class, GuardedApproval::class],
            [WaitingForChildren::class, Approved::class, GuardedApproval::class],
        ]]);
    $nodes = [new Node('owner', ApproveStep::class)];
    if ($parent) {
        $nodes[] = controlled('child', parent: 'owner');
    }
    $flow = app(Engine::class)->start(new Workflow($nodes));
    runStep($flow->id, 'owner');
    if ($parent) {
        runStep($flow->id, 'child');
        expect(statesOf($flow->id)['child'])->toBe('completed');
    }
    expect(statesOf($flow->id)['owner'])->toBe($allowed ? 'approved' : 'failed');
    expect($flow->fresh()->status)->toBe($allowed ? 'completed' : 'failed');
})->with([[false, false], [true, false], [false, true], [true, true]]);

it('settles each descendant rescue branch before failing its parent', function () {
    Queue::fake();
    $flow = app(Engine::class)->start(new Workflow([
        controlled('grandparent'), controlled('parent', parent: 'grandparent'),
        controlled('child', 'fail', parent: 'parent'),
        controlled('child-rescue', rescues: 'child'),
        controlled('parent-rescue', rescues: 'parent'),
        controlled('grandparent-rescue', rescues: 'grandparent'),
        controlled('after', stage: 1),
    ]));
    runStep($flow->id, 'grandparent');
    runStep($flow->id, 'parent');
    expect(statesOf($flow->id))->toBe([
        'after' => 'pending', 'child' => 'queued', 'child-rescue' => 'pending',
        'grandparent' => 'waitingforchildren', 'grandparent-rescue' => 'pending',
        'parent' => 'waitingforchildren', 'parent-rescue' => 'pending',
    ]);
    runStep($flow->id, 'child');
    expect(statesOf($flow->id))->toBe([
        'after' => 'pending', 'child' => 'failed', 'child-rescue' => 'queued',
        'grandparent' => 'waitingforchildren', 'grandparent-rescue' => 'pending',
        'parent' => 'waitingforchildren', 'parent-rescue' => 'pending',
    ]);
    // Premature deliveries cannot construct or run an ancestor's rescue.
    runStep($flow->id, 'grandparent-rescue');
    runStep($flow->id, 'parent-rescue');
    runStep($flow->id, 'child-rescue');
    expect(statesOf($flow->id))->toBe([
        'after' => 'pending', 'child' => 'failed', 'child-rescue' => 'completed',
        'grandparent' => 'waitingforchildren', 'grandparent-rescue' => 'pending',
        'parent' => 'failed', 'parent-rescue' => 'queued',
    ])->and($flow->fresh()->status)->toBe('running');
    runStep($flow->id, 'parent-rescue');
    expect(statesOf($flow->id))->toBe([
        'after' => 'cancelled', 'child' => 'failed', 'child-rescue' => 'completed',
        'grandparent' => 'failed', 'grandparent-rescue' => 'queued',
        'parent' => 'failed', 'parent-rescue' => 'completed',
    ]);
    runStep($flow->id, 'grandparent-rescue');
    expect($flow->fresh()->status)->toBe('failed')
        ->and(statesOf($flow->id)['after'])->toBe('cancelled');
});

it('rejects state declarations that cannot be constructed or that break failure semantics', function (string $class, string $message) {
    config(['scarlett.states' => [$class]]);

    expect(fn () => (new StateRegistry)->classes())->toThrow(InvalidArgumentException::class, $message);
})->with([
    'abstract state class' => [AbstractApprovalState::class, 'concrete'],
]);

it('rejects custom state aliases that the state column cannot store', function (string $alias, string $message) {
    AliasedState::$name = $alias;

    try {
        config(['scarlett.states' => [AliasedState::class]]);

        expect(fn () => (new StateRegistry)->classes())->toThrow(InvalidArgumentException::class, $message);
    } finally {
        AliasedState::$name = null;
    }
})->with([
    'alias that is not UTF-8' => ["caf\xE9", 'UTF-8'],
    'alias longer than 255 characters' => [str_repeat('a', 256), '255 characters'],
]);

it('rejects custom transition classes that cannot be constructed', function () {
    config(['scarlett.states' => [Approved::class], 'scarlett.transitions' => [[Running::class, Approved::class, CustomAlertTransition::class]]]);

    expect(fn () => (new StateRegistry)->transitions())->toThrow(InvalidArgumentException::class, 'concrete');
});

it('accepts a skipping settled state and a valid custom state alias at the column limit', function () {
    config(['scarlett.states' => [Approved::class]]);
    expect((new StateRegistry)->classes())->toContain(Approved::class, Skipped::class)
        ->and(Skipped::skipsChildren())->toBeTrue();

    AliasedState::$name = str_repeat('a', 255);

    try {
        config(['scarlett.states' => [AliasedState::class]]);

        expect((new StateRegistry)->classes())->toContain(AliasedState::class);
    } finally {
        AliasedState::$name = null;
    }
});
