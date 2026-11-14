<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Scarlett\Definition\ExecutionOptions;
use Scarlett\Definition\Node;
use Scarlett\Definition\Workflow;
use Scarlett\Engine;
use Scarlett\Execution\Decision;
use Scarlett\Execution\Runner;
use Scarlett\Inputs\FromResult;
use Scarlett\Inputs\PayloadCodec;
use Scarlett\States\Completed;
use Scarlett\States\Running;
use Scarlett\States\WaitingForChildren;
use Scarlett\Tests\Fixtures\Approved;
use Scarlett\Tests\Fixtures\ControlledStep;
use Scarlett\Tests\Fixtures\FailureAwareStep;
use Scarlett\Tests\Fixtures\GuardedApproval;
use Scarlett\Tests\Fixtures\RuntimeParent;

beforeEach(function () {
    Queue::fake();
    ControlledStep::$actions = [];
    ControlledStep::$constructed = [];
    RuntimeParent::$actions = [];
    RuntimeParent::$verified = [];
    RuntimeParent::$verificationPasses = true;
});

it('hands off nested backups and verifies the effective result before admitting successors', function (string $entry, mixed $value) {
    $flow = app(Engine::class)->start(new Workflow([
        new Node('original', RuntimeParent::class, inputs: ['depth' => 2, 'entry' => $entry, 'value' => $value]),
        new Node('next', ControlledStep::class, stage: 1, inputs: ['name' => 'next', 'value' => new FromResult('original')]),
    ]));
    $original = $flow->steps()->where('key', 'original')->sole();
    expect(statesOf($flow->id))->toBe(['next' => 'pending', 'original' => 'queued']);
    runStep($flow->id, 'original');
    expect((string) $original->fresh()->state)->toBe('waitingforchildren');
    expect(RuntimeParent::$verified)->toBe([]);
    $backup = $flow->steps()->where('parent_key', 'original')->sole();
    runStep($flow->id, $backup->key);
    $leaf = $flow->steps()->where('parent_key', $backup->key)->sole();
    runStep($flow->id, $leaf->key);
    expect((string) $backup->fresh()->state)->toBe('queued');
    expect((string) $original->fresh()->state)->toBe('waitingforchildren');
    runStep($flow->id, $backup->key);
    expect((string) $original->fresh()->state)->toBe('queued');
    expect((string) $flow->steps()->where('key', 'next')->sole()->state)->toBe('pending');
    runStep($flow->id, 'original');
    runStep($flow->id, 'next');
    expect($flow->fresh()->status)->toBe('completed');
    expect(app(PayloadCodec::class)->decode($flow->steps()->where('key', 'next')->sole()->result))->toBe($value);
    expect(RuntimeParent::$verified)->toBe([[0, $value], [1, $value], [2, $value]]);
    expect(RuntimeParent::$actions)->toBe($entry === 'before' ? [1, 0] : [2, 1, 0]);
    app(Runner::class)->run($original->id, $original->attempt);
    expect($flow->steps()->count())->toBe(4);
})->with(['handle', 'before', 'exception'])->with(['answer', null]);

it('admits staged fanout atomically once and forwards local prerequisite inputs', function () {
    $engine = app(Engine::class);
    $flow = $engine->start(new Workflow([controlled('parent'), controlled('successor', stage: 1)]));
    $parent = $flow->steps()->where('key', 'parent')->sole();
    $engine->claim($parent->id, $parent->attempt);
    $plan = Decision::children([
        new Node('a', ControlledStep::class, inputs: ['name' => 'a', 'value' => 42]),
        controlled('b'),
        new Node('c', ControlledStep::class, stage: 1, inputs: ['name' => 'c', 'value' => new FromResult('a')]),
    ], resultFrom: 'c');
    expect($engine->decide($parent->id, $parent->attempt, $plan))->toBeTrue();
    expect($engine->decide($parent->id, $parent->attempt, $plan))->toBeFalse();
    expect(ControlledStep::$constructed)->toBe([]);
    $children = $flow->steps()->where('parent_key', 'parent')->orderBy('key')->get();
    expect($children->map(fn ($s) => (string) $s->state)->all())->toBe(['queued', 'queued', 'pending']);
    runStep($flow->id, $children[0]->key);
    expect((string) $children[2]->fresh()->state)->toBe('pending');
    runStep($flow->id, $children[1]->key);
    runStep($flow->id, $children[2]->key);
    runStep($flow->id, 'parent');
    expect(app(PayloadCodec::class)->decode($parent->fresh()->result))->toBe(42);
    expect((string) $flow->steps()->where('key', 'successor')->sole()->state)->toBe('queued');
    expect($flow->steps()->count())->toBe(5);
});

it('rejects stale cancelled and expired attempts without adding children', function (string $loss) {
    $engine = app(Engine::class);
    $flow = $engine->start(new Workflow([controlled('parent')]));
    $parent = $flow->steps()->sole();
    $engine->claim($parent->id, $parent->attempt);
    if ($loss === 'cancel') {
        $engine->cancelWorkflow($flow->id);
    } elseif ($loss === 'expire') {
        $parent->update(['lease_until' => now()->subSecond()]);
    }
    expect($engine->decide($parent->id, $loss === 'stale' ? 'old-attempt' : $parent->attempt, Decision::children([controlled('child')])))->toBeFalse();
    expect($flow->steps()->count())->toBe(1);
})->with(['stale', 'cancel', 'expire']);

it('verifies an empty child plan without replaying the parent action', function () {
    $engine = app(Engine::class);
    $flow = $engine->start(new Workflow([controlled('empty')]));
    $parent = $flow->steps()->sole();
    $engine->claim($parent->id, $parent->attempt);
    expect($engine->decide($parent->id, $parent->attempt, Decision::children([], result: ['count' => 0])))->toBeTrue();
    expect((string) $parent->fresh()->state)->toBe('queued');
    runStep($flow->id, 'empty');
    expect($flow->fresh()->status)->toBe('completed');
    expect(ControlledStep::$actions)->not->toHaveKey('empty');
    expect(app(PayloadCodec::class)->decode($parent->fresh()->result))->toBe(['count' => 0]);
});

it('rolls back invalid child plans without changing the running parent', function (string $invalid) {
    $engine = app(Engine::class);
    $flow = $engine->start(new Workflow([controlled('parent')]));
    $parent = $flow->steps()->sole();
    $engine->claim($parent->id, $parent->attempt);
    $nodes = match ($invalid) {
        'duplicate' => [controlled('same'), controlled('same')],
        'external' => [new Node('a', ControlledStep::class, inputs: ['name' => 'a', 'value' => new FromResult('parent')])],
        'cycle' => [controlled('a', parent: 'b'), controlled('b', parent: 'a')],
        'long' => [controlled(str_repeat('é', Node::MAX_KEY_LENGTH))],
        'literal' => [new Node('a', ControlledStep::class, inputs: ['name' => 'a', 'value' => new stdClass])],
        default => [controlled('valid')],
    };
    $decision = Decision::children($nodes, resultFrom: $invalid === 'source' ? 'missing' : null);
    expect((string) $parent->fresh()->state)->toBe('running');
    expect(fn () => $engine->decide($parent->id, $parent->attempt, $decision))->toThrow(InvalidArgumentException::class);
    expect($flow->steps()->count())->toBe(1);
    expect((string) $parent->fresh()->state)->toBe('running');
    expect($parent->fresh()->action_completed)->toBeFalse();
})->with(['duplicate', 'external', 'cycle', 'long', 'literal', 'source']);

it('rejects a second plan on predefined children and during verification', function (bool $predefined) {
    $engine = app(Engine::class);
    $flow = $engine->start(new Workflow($predefined ? [controlled('parent'), controlled('fixed', parent: 'parent')] : [controlled('parent')]));
    $parent = $flow->steps()->where('key', 'parent')->sole();
    $engine->claim($parent->id, $parent->attempt);
    if (! $predefined) {
        $engine->saveAction($parent->id, $parent->attempt, 'saved', Completed::class);
    }
    expect(fn () => $engine->decide($parent->id, $parent->attempt, Decision::children([controlled('extra')])))->toThrow(InvalidArgumentException::class);
    expect($flow->steps()->count())->toBe($predefined ? 2 : 1);
    expect((string) $parent->fresh()->state)->toBe('running');
})->with([true, false]);

it('inherits routing through local ancestors while preserving explicit zero priority and execution options', function () {
    $engine = app(Engine::class);
    $flow = $engine->start(new Workflow([new Node('parent', ControlledStep::class, inputs: ['name' => 'parent'], queue: 'high', connection: 'sync', priority: 9)]));
    $parent = $flow->steps()->sole();
    $engine->claim($parent->id, $parent->attempt);
    $engine->decide($parent->id, $parent->attempt, Decision::children([
        new Node('nested', ControlledStep::class, inputs: ['name' => 'nested'], parent: 'override'),
        new Node('override', ControlledStep::class, inputs: ['name' => 'override'], queue: 'low', connection: 'null', priority: 0, execution: new ExecutionOptions(attempts: 7)),
        controlled('inherited'),
        controlled('rescue', rescues: 'override'),
    ]));
    $children = $flow->steps()->where('key', '!=', 'parent')->get()->keyBy(fn ($s) => substr($s->key, strlen($parent->id) + 1));
    foreach (['override', 'nested', 'rescue'] as $key) {
        expect([$children[$key]->queue, $children[$key]->connection, (int) $children[$key]->priority])->toBe(['low', 'null', 0]);
    }
    expect([$children['inherited']->queue, $children['inherited']->connection, (int) $children['inherited']->priority])->toBe(['high', 'sync', 9]);
    expect($children['override']->executionOptions()['retry']['attempts'])->toBe(7);
    expect($children['nested']->executionOptions()['retry']['attempts'])->toBe(config('scarlett.retry.attempts'));
});

it('drains runtime child rescues before failing the owner and admitting its rescue', function (string $outcome) {
    $engine = app(Engine::class);
    $flow = $engine->start(new Workflow([controlled('parent'), controlled('parent-rescue', rescues: 'parent'), controlled('after', stage: 1)]));
    $parent = $flow->steps()->where('key', 'parent')->sole();
    $engine->claim($parent->id, $parent->attempt);
    $engine->decide($parent->id, $parent->attempt, Decision::children([
        controlled('child', $outcome), controlled('child-rescue', rescues: 'child'),
        controlled('later', stage: 1),
    ], resultFrom: 'child'));
    runStep($flow->id, $parent->id.'/child');
    expect((string) $parent->fresh()->state)->toBe('waitingforchildren');
    expect((string) $flow->steps()->where('key', 'parent-rescue')->sole()->state)->toBe('pending');
    expect((string) $flow->steps()->where('key', $parent->id.'/later')->sole()->state)->toBe('cancelled');
    runStep($flow->id, $parent->id.'/child-rescue');
    expect((string) $parent->fresh()->state)->toBe('failed');
    expect((string) $flow->steps()->where('key', 'parent-rescue')->sole()->state)->toBe('queued');
    expect((string) $flow->steps()->where('key', 'after')->sole()->state)->toBe('cancelled');
    expect($parent->fresh()->has_result)->toBeFalse();
    runStep($flow->id, 'parent-rescue');
    expect($flow->fresh()->status)->toBe('failed');
})->with(['fail', 'stop']);

it('fails clearly when the selected child skips without producing a result', function () {
    $engine = app(Engine::class);
    $flow = $engine->start(new Workflow([controlled('parent')]));
    $parent = $flow->steps()->sole();
    $engine->claim($parent->id, $parent->attempt);
    $engine->decide($parent->id, $parent->attempt, Decision::children([controlled('skipped', 'skip')], resultFrom: 'skipped'));
    runStep($flow->id, $parent->id.'/skipped');
    expect((string) $parent->fresh()->state)->toBe('failed');
    expect($parent->fresh()->error)->toBe('The selected child did not produce a result.');
    expect($flow->fresh()->status)->toBe('failed');
});

it('recovers parent verification without replaying actions or adding more children', function (string $failure) {
    config(['scarlett.verification.backoff_seconds' => [0]]);
    $engine = app(Engine::class);
    $flow = $engine->start(new Workflow([new Node('parent', RuntimeParent::class)]));
    runStep($flow->id, 'parent');
    $parent = $flow->steps()->where('key', 'parent')->sole();
    runStep($flow->id, $parent->id.'/backup');
    expect((string) $parent->fresh()->state)->toBe('queued');
    if ($failure === 'lease') {
        $attempt = $parent->fresh()->attempt;
        $engine->claim($parent->id, $attempt);
        $parent->update(['lease_until' => now()->subSecond()]);
        expect($engine->recover($parent->id))->toBeTrue();
        expect($engine->decide($parent->id, $attempt, Decision::children([controlled('duplicate')])))->toBeFalse();
    } else {
        RuntimeParent::$verificationPasses = false;
        runStep($flow->id, 'parent');
        RuntimeParent::$verificationPasses = true;
    }
    expect((string) $parent->fresh()->state)->toBe('queued');
    expect($parent->fresh()->verifications)->toBe(1);
    runStep($flow->id, 'parent');
    expect($flow->fresh()->status)->toBe('completed');
    expect(RuntimeParent::$actions)->toBe([1, 0]);
    expect($flow->steps()->count())->toBe(2);
})->with(['lease', 'verify']);

it('retries before handoff and recovers lost child delivery without duplicating the plan', function () {
    config(['scarlett.retry.backoff_seconds' => [0]]);
    $engine = app(Engine::class);
    $flow = $engine->start(new Workflow([controlled('parent')]));
    $parent = $flow->steps()->sole();
    $engine->claim($parent->id, $parent->attempt);
    $engine->decide($parent->id, $parent->attempt, Decision::retry());
    $newAttempt = $parent->fresh()->attempt;
    expect($newAttempt)->not->toBe($parent->attempt);
    $engine->claim($parent->id, $newAttempt);
    expect($engine->decide($parent->id, $newAttempt, Decision::children([controlled('child')])))->toBeTrue();
    $child = $flow->steps()->where('parent_key', 'parent')->sole();
    DB::table('scarlett_deliveries')->where('step_id', $child->id)->update(['sent_at' => null]);
    expect($engine->recover($child->id))->toBeTrue();
    runStep($flow->id, $child->key);
    runStep($flow->id, 'parent');
    expect($flow->fresh()->status)->toBe('completed');
    expect($flow->steps()->count())->toBe(2);
});

it('keeps the runtime schema until active plans finish and preserves results through upgrade', function () {
    $migration = require __DIR__.'/../../database/migrations/2026_11_14_000007_add_runtime_children.php';
    $engine = app(Engine::class);
    $flow = $engine->start(new Workflow([new Node('parent', RuntimeParent::class)]));
    runStep($flow->id, 'parent');
    $parent = $flow->steps()->where('key', 'parent')->sole();
    expect(fn () => $migration->down())->toThrow(RuntimeException::class, 'Drain runtime child workflows');
    expect(Schema::hasColumn('scarlett_steps', 'child_phase'))->toBeTrue();
    runStep($flow->id, $parent->id.'/backup');
    runStep($flow->id, 'parent');
    $migration->down();
    expect(Schema::hasColumn('scarlett_steps', 'child_phase'))->toBeFalse();
    $migration->up();
    expect($parent->fresh()->child_phase)->toBeNull();
    expect(app(PayloadCodec::class)->decode($parent->fresh()->result))->toBe('backup result');
    $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
});

it('rolls back the complete handoff with its enclosing transaction', function () {
    $engine = app(Engine::class);
    $flow = $engine->start(new Workflow([controlled('parent')]));
    $parent = $flow->steps()->sole();
    $engine->claim($parent->id, $parent->attempt);
    Queue::fake();
    expect(fn () => DB::transaction(function () use ($engine, $parent) {
        expect($engine->decide($parent->id, $parent->attempt, Decision::children([controlled('child')])))->toBeTrue();
        throw new RuntimeException('Undo consumer transaction');
    }))->toThrow(RuntimeException::class, 'Undo consumer transaction');
    expect((string) $parent->fresh()->state)->toBe('running');
    expect($parent->fresh()->action_completed)->toBeFalse();
    expect($flow->steps()->count())->toBe(1);
    Queue::assertNothingPushed();
    expect($engine->decide($parent->id, $parent->attempt, Decision::children([controlled('child')])))->toBeTrue();
    expect($flow->steps()->count())->toBe(2);
});

it('preserves typed child results and defers scheduled children at the exact key boundary', function () {
    $engine = app(Engine::class);
    $flow = $engine->start(new Workflow([controlled('parent')]));
    $parent = $flow->steps()->sole();
    $engine->claim($parent->id, $parent->attempt);
    $key = str_repeat('é', Node::MAX_KEY_LENGTH - strlen($parent->id.'/'));
    $date = new DateTimeImmutable('2040-05-06T10:20:30+00:00');
    $when = now()->addMinute();
    $engine->decide($parent->id, $parent->attempt, Decision::children([
        new Node($key, ControlledStep::class, inputs: ['name' => 'dated', 'value' => $date], notBefore: $when),
    ], resultFrom: $key));
    $child = $flow->steps()->where('parent_key', 'parent')->sole();
    expect(mb_strlen($child->key))->toBe(Node::MAX_KEY_LENGTH);
    expect($engine->claim($child->id, $child->attempt))->toBeNull();
    expect($child->available_at->toDateTimeString())->toBe($when->toDateTimeString());
    $this->travelTo($when->copy()->addSecond());
    runStep($flow->id, $child->key);
    runStep($flow->id, 'parent');
    expect(app(PayloadCodec::class)->decode($parent->fresh()->result)->format(DATE_ATOM))->toBe($date->format(DATE_ATOM));
    expect($flow->fresh()->status)->toBe('completed');
});

it('runs runtime child failure hooks before the parent and preserves the typed cause', function (string $mode, string $cause) {
    FailureAwareStep::$constructionThrows = false;
    FailureAwareStep::$hookThrows = false;
    $engine = app(Engine::class);
    $flow = $engine->start(new Workflow([new Node('parent', FailureAwareStep::class, inputs: ['name' => 'parent'])]));
    $parent = $flow->steps()->sole();
    $engine->claim($parent->id, $parent->attempt);
    $engine->decide($parent->id, $parent->attempt, Decision::children([
        new Node('child', FailureAwareStep::class, inputs: ['name' => 'child', 'mode' => $mode]),
    ], resultFrom: 'child'));
    runStep($flow->id, $parent->id.'/child');
    $parentFailure = $flow->failures()->where('step_id', $parent->id)->sole();
    expect($parentFailure->cause->value)->toBe($cause);
    if ($mode === 'fail') {
        expect($parentFailure->status->value)->toBe('pending');
        $child = $flow->steps()->where('parent_key', 'parent')->sole();
        $failure = $flow->failures()->where('step_id', $child->id)->sole();
        app(Scarlett\Failures\Runner::class)->run($failure->id, $failure->attempt);
        $parentFailure->refresh();
    }
    expect($parentFailure->status->value)->toBe('queued');
    app(Scarlett\Failures\Runner::class)->run($parentFailure->id, $parentFailure->attempt);
    expect(array_column($flow->fresh()->metadata['failures'], 'name'))->toBe($mode === 'fail' ? ['child', 'parent'] : ['parent']);
    expect($flow->fresh()->status)->toBe('failed');
})->with([['fail', 'child_failed'], ['skip', 'child_result_missing']]);

it('applies configured completion guards after runtime children and parent verification', function (bool $allowed) {
    GuardedApproval::$allowed = $allowed;
    config(['scarlett.states' => [Approved::class], 'scarlett.transitions' => [
        [Running::class, Approved::class, GuardedApproval::class],
        [WaitingForChildren::class, Approved::class, GuardedApproval::class],
    ]]);
    $engine = app(Engine::class);
    $flow = $engine->start(new Workflow([controlled('parent'), controlled('after', stage: 1)]));
    $parent = $flow->steps()->where('key', 'parent')->sole();
    $engine->claim($parent->id, $parent->attempt);
    expect($engine->decide($parent->id, $parent->attempt, Decision::children([controlled('child')], state: Approved::class)))->toBeTrue();
    runStep($flow->id, $parent->id.'/child');
    expect((string) $parent->fresh()->state)->toBe('queued');
    runStep($flow->id, 'parent');
    expect((string) $flow->steps()->where('key', $parent->id.'/child')->sole()->state)->toBe('completed');
    expect((string) $parent->fresh()->state)->toBe($allowed ? 'approved' : 'failed');
    expect((string) $flow->steps()->where('key', 'after')->sole()->state)->toBe($allowed ? 'queued' : 'cancelled');
})->with([true, false]);
