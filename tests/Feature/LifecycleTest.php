<?php

declare(strict_types=1);

/**
 * Checks the ordinary sequence, parallel, child, and rescue execution paths.
 *
 * Queue fakes and runStep() let each case choose which attempt completes next. Observe
 * when a successor becomes queued and when its object is constructed, proving the
 * engine waits for all required outcomes and restores input results only when needed.
 * Numeric-looking keys guard against treating a valid zero key as an absent link.
 */

use Illuminate\Support\Facades\Queue;
use Scarlett\Definition\ExecutionOptions;
use Scarlett\Definition\Node;
use Scarlett\Definition\Workflow;
use Scarlett\Engine;
use Scarlett\Inputs\FromResult;
use Scarlett\Inputs\PayloadCodec;
use Scarlett\Tests\Fixtures\ControlledStep;
use Scarlett\Tests\Fixtures\EchoStep;

beforeEach(function () {
    ControlledStep::$actions = [];
    ControlledStep::$constructed = [];
    Queue::fake();
});
it('holds the parallel barrier until both outcomes and constructs the successor late', function () {
    $workflow = app(Engine::class)->start(new Workflow([
        new Node('a', ControlledStep::class, inputs: ['name' => 'a', 'value' => 42]), controlled('b'),
        new Node('c', ControlledStep::class, stage: 1, inputs: ['name' => 'c', 'value' => new FromResult('a')]),
    ]));
    expect(ControlledStep::$constructed)->toBe([]);
    runStep($workflow->id, 'a');
    expect(statesOf($workflow->id))->toBe(['a' => 'completed', 'b' => 'queued', 'c' => 'pending']);
    runStep($workflow->id, 'b');
    expect(statesOf($workflow->id)['c'])->toBe('queued');
    expect(ControlledStep::$constructed)->toBe([['a', 42], ['b', null]]);
    runStep($workflow->id, 'c');
    expect(ControlledStep::$constructed)->toBe([['a', 42], ['b', null], ['c', 42]]);
});
it('waits for nested children before releasing the parent successor', function () {
    $workflow = app(Engine::class)->start(new Workflow([controlled('parent'), controlled('child', parent: 'parent'), controlled('grandchild', parent: 'child'), controlled('after', stage: 1)]));
    expect(statesOf($workflow->id))->toBe(['after' => 'pending', 'child' => 'pending', 'grandchild' => 'pending', 'parent' => 'queued']);
    runStep($workflow->id, 'parent');
    expect(statesOf($workflow->id))->toBe(['after' => 'pending', 'child' => 'queued', 'grandchild' => 'pending', 'parent' => 'waitingforchildren']);
    runStep($workflow->id, 'child');
    runStep($workflow->id, 'grandchild');
    expect(statesOf($workflow->id))->toBe(['after' => 'queued', 'child' => 'completed', 'grandchild' => 'completed', 'parent' => 'completed']);
});
it('propagates failure and activates only the applicable recovery path', function () {
    $workflow = app(Engine::class)->start(new Workflow([controlled('a', 'fail'), controlled('b', stage: 1), controlled('rescue', rescues: 'a')]));
    expect(statesOf($workflow->id))->toBe(['a' => 'queued', 'b' => 'pending', 'rescue' => 'pending']);
    runStep($workflow->id, 'a');
    expect(statesOf($workflow->id))->toBe(['a' => 'failed', 'b' => 'cancelled', 'rescue' => 'queued']);
    expect(ControlledStep::$actions)->toBe([]);
    runStep($workflow->id, 'rescue');
    expect($workflow->fresh()->status)->toBe('failed');
});
it('skips work without executing its action and continues the next stage', function () {
    $workflow = app(Engine::class)->start(new Workflow([controlled('a', 'skip'), controlled('b', stage: 1)]));
    runStep($workflow->id, 'a');
    expect(statesOf($workflow->id))->toBe(['a' => 'skipped', 'b' => 'queued']);
    expect(ControlledStep::$actions)->toBe([]);
});
it('skips a whole predefined subtree and leaves unrelated branches untouched', function () {
    $flow = app(Engine::class)->start(new Workflow([controlled('parent', 'skip'), controlled('child', parent: 'parent'), controlled('leaf', parent: 'child'), controlled('peer'), controlled('after', stage: 1)]));
    runStep($flow->id, 'parent');
    expect(statesOf($flow->id))->toBe(['after' => 'pending', 'child' => 'skipped', 'leaf' => 'skipped', 'parent' => 'skipped', 'peer' => 'queued']);
    expect(ControlledStep::$actions)->toBe([]);
});
it('cancels queued children once their parent fails', function () {
    $flow = app(Engine::class)->start(new Workflow([controlled('parent'), controlled('failed', 'fail', parent: 'parent'), controlled('queued', parent: 'parent')]));
    runStep($flow->id, 'parent');
    expect(statesOf($flow->id)['queued'])->toBe('queued');
    runStep($flow->id, 'failed');
    expect(statesOf($flow->id))->toBe(['failed' => 'failed', 'parent' => 'failed', 'queued' => 'cancelled']);
    runStep($flow->id, 'queued');
    expect(ControlledStep::$actions)->toBe(['parent' => 1]);
});

it('keeps numeric-looking parent keys distinct', function (string $root, string $branch) {
    Queue::fake();
    $leaf = $branch.'.leaf';
    $flow = app(Engine::class)->start(new Workflow([
        controlled($root), controlled($branch, parent: $root), controlled($leaf, parent: $branch),
    ]));
    expect($flow->fresh()->status)->toBe('running');
    runStep($flow->id, $root);
    runStep($flow->id, $branch);
    runStep($flow->id, $leaf);
    expect($flow->fresh()->status)->toBe('completed');
    expect($flow->steps()->get()->map(fn ($step) => (string) $step->state)->all())->toBe(['completed', 'completed', 'completed']);
})->with([['1', '1.0'], ['01', '1'], ['1e1', '10'], ['a', 'a.0']]);

it('persists progression failure reasons for recovery consumers', function () {
    Queue::fake();
    $flow = app(Engine::class)->start(new Workflow([controlled('parent'), controlled('child', 'fail', parent: 'parent'), controlled('after', stage: 1)]));
    runStep($flow->id, 'parent');
    runStep($flow->id, 'child');
    expect($flow->steps()->where('key', 'parent')->sole()->error)->toBe('A child failed.');
    expect($flow->steps()->where('key', 'after')->sole()->error)->toBe('Prerequisite did not succeed.');
});

it('binds results from a zero-valued parent or rescue owner key', function (string $key, bool $rescue) {
    Queue::fake();
    $flow = app(Engine::class)->start(new Workflow([
        new Node($key, ControlledStep::class, inputs: ['name' => 'owner', 'mode' => $rescue ? 'verify' : 'success', 'value' => 42], execution: new ExecutionOptions(verificationAttempts: 1)),
        new Node('consumer', EchoStep::class, inputs: ['value' => new FromResult($key)], parent: $rescue ? null : $key, rescues: $rescue ? $key : null),
    ]));
    expect((string) $flow->steps()->where('key', 'consumer')->sole()->state)->toBe('pending');
    runStep($flow->id, $key);
    expect((string) $flow->steps()->where('key', 'consumer')->sole()->state)->toBe('queued');
    runStep($flow->id, 'consumer');
    expect(app(PayloadCodec::class)->decode($flow->steps()->where('key', 'consumer')->sole()->result))->toBe(42);
    expect($flow->fresh()->status)->toBe($rescue ? 'failed' : 'completed');
})->with([['0', false], ['0', true], ['owner', false], ['owner', true]]);
