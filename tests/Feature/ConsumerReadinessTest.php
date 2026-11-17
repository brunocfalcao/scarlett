<?php

declare(strict_types=1);

/**
 * Exercises application-facing guarantees across timing, ownership, and persistence.
 *
 * Queue fakes let cases stop at exact lifecycle boundaries and deliver chosen steps.
 * Saved per-node budgets, guarded writes, subject lookup, and cancellation are checked
 * through engine calls and database state. Independent branches and workflows provide
 * controls so a rejected or failed step cannot appear correct by affecting everything.
 */

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Scarlett\Definition\ExecutionOptions;
use Scarlett\Definition\Node;
use Scarlett\Definition\Workflow;
use Scarlett\Engine;
use Scarlett\Execution\Context;
use Scarlett\History;
use Scarlett\Jobs\ExecuteStep;
use Scarlett\Models\WorkflowRecord;
use Scarlett\States\Completed;
use Scarlett\Tests\Fixtures\ContextStep;
use Scarlett\Tests\Fixtures\ControlledStep;

beforeEach(function () {
    Queue::fake();
    $this->freezeSecond();
    ControlledStep::$actions = [];
});

it('keeps a scheduled root dormant until due and immediately releases its known children', function () {
    $flow = app(Engine::class)->start(new Workflow([
        new Node('root', ControlledStep::class, inputs: ['name' => 'root'], notBefore: now()->addSeconds(120)),
        controlled('child', parent: 'root'),
    ]));
    expect(statesOf($flow->id))->toBe(['child' => 'pending', 'root' => 'queued']);
    runStep($flow->id, 'root');
    expect(ControlledStep::$actions)->toBe([])->and(app(Engine::class)->stalled($flow->id))->toBeFalse();
    $this->travel(120)->seconds();
    runStep($flow->id, 'root');
    expect(statesOf($flow->id))->toBe(['child' => 'queued', 'root' => 'waitingforchildren']);
    runStep($flow->id, 'child');
    expect($flow->fresh()->status)->toBe('completed');
});

it('waits for both prerequisites and a scheduled time and never delays work already due', function () {
    $flow = app(Engine::class)->start(new Workflow([
        controlled('first'),
        new Node('next', ControlledStep::class, stage: 1, inputs: ['name' => 'next'], notBefore: now()->addSeconds(10)),
    ]));
    $this->travel(20)->seconds();
    expect(statesOf($flow->id))->toBe(['first' => 'queued', 'next' => 'pending']);
    runStep($flow->id, 'first');
    expect($flow->steps()->where('key', 'next')->sole()->available_at->equalTo(now()))->toBeTrue();
    runStep($flow->id, 'next');
    expect($flow->fresh()->status)->toBe('completed');
});

it('snapshots individual execution limits for dispatch retry heartbeat and crash recovery', function () {
    $options = new ExecutionOptions(workerTimeout: 1600, leaseSeconds: 1700, attempts: 2, backoffSeconds: [13]);
    $flow = app(Engine::class)->start(new Workflow([
        new Node('long', ControlledStep::class, inputs: ['name' => 'long', 'mode' => 'throw'], execution: $options),
        controlled('ordinary'),
    ]));
    $long = $flow->steps()->where('key', 'long')->sole();
    Queue::assertPushed(ExecuteStep::class, fn ($job) => $job->stepId === $long->id && $job->timeout === 1600);
    config(['scarlett.lease_seconds' => 30, 'scarlett.worker_timeout' => 20, 'scarlett.retry.attempts' => 8]);
    runStep($flow->id, 'long');
    expect($long->fresh()->failures)->toBe(1)->and($long->fresh()->available_at->equalTo(now()->addSeconds(13)))->toBeTrue();
    $this->travel(13)->seconds();
    $long = $long->fresh();
    app(Engine::class)->claim($long->id, $long->attempt);
    expect($long->fresh()->lease_until->equalTo(now()->addSeconds(1700)))->toBeTrue();
    app(Engine::class)->heartbeat($long->id, $long->attempt);
    $this->travel(1700)->seconds();
    expect(app(Engine::class)->recover($long->id))->toBeTrue();
    expect(statesOf($flow->id))->toBe(['long' => 'failed', 'ordinary' => 'queued']);
});

it('rejects invalid execution options before storing any workflow', function () {
    $before = WorkflowRecord::count();
    expect(fn () => app(Engine::class)->start(new Workflow([
        new Node('invalid', ControlledStep::class, inputs: ['name' => 'invalid'], execution: new ExecutionOptions(workerTimeout: 30, leaseSeconds: 30)),
    ])))->toThrow(InvalidArgumentException::class);
    expect(WorkflowRecord::count())->toBe($before);
    Queue::assertNothingPushed();
});

it('revokes an entire workflow including running work without invoking its failure branches', function () {
    $engine = app(Engine::class);
    $flow = $engine->start(new Workflow([controlled('parent'), controlled('child', parent: 'parent'), controlled('rescue', rescues: 'parent')]));
    $other = $engine->start(new Workflow([controlled('untouched')]));
    runStep($flow->id, 'parent');
    $child = $flow->steps()->where('key', 'child')->sole();
    $engine->claim($child->id, $child->attempt);
    expect(statesOf($flow->id))->toBe(['child' => 'running', 'parent' => 'waitingforchildren', 'rescue' => 'pending']);
    expect($engine->cancelWorkflow($flow->id))->toBeTrue();
    expect(statesOf($flow->id))->toBe(['child' => 'cancelled', 'parent' => 'cancelled', 'rescue' => 'cancelled']);
    expect($engine->isCurrent($child->id, $child->attempt))->toBeFalse();
    expect($engine->saveAction($child->id, $child->attempt, 'obsolete', Completed::class))->toBeFalse();
    expect($engine->cancelWorkflow($flow->id))->toBeFalse();
    expect(statesOf($other->id))->toBe(['untouched' => 'queued']);
});

it('injects attempt context into typed signatures and fences transactional consumer writes', function () {
    $flow = app(Engine::class)->start(new Workflow([new Node('write', ContextStep::class)]));
    $step = $flow->steps()->sole();
    runStep($flow->id, 'write');
    expect($flow->fresh()->metadata)->toBe(['written' => true]);
    expect((string) $step->fresh()->state)->toBe('completed');
    $context = new Context($flow->id, $step->id, $step->attempt, 0);
    expect($context->whileCurrent(fn () => $flow->update(['metadata' => ['written' => false]])))->toBeFalse();
    expect($flow->fresh()->metadata)->toBe(['written' => true]);
});

it('rolls back a guarded write on exception and refuses an expired lease', function () {
    $engine = app(Engine::class);
    $flow = $engine->start(new Workflow([controlled('a')]));
    $step = $flow->steps()->sole();
    $engine->claim($step->id, $step->attempt);
    $context = new Context($flow->id, $step->id, $step->attempt, 0);
    expect(fn () => $context->whileCurrent(function () use ($flow) {
        $flow->update(['metadata' => ['invalid' => true]]);
        throw new RuntimeException('Rollback the business write');
    }))->toThrow(RuntimeException::class);
    expect($flow->fresh()->metadata)->toBe([]);
    $step->fresh()->update(['lease_until' => now()]);
    expect($context->whileCurrent(fn () => $flow->update(['metadata' => ['invalid' => true]])))->toBeFalse();
    expect($flow->fresh()->metadata)->toBe([]);
});

it('finds workflows by explicit subject and deletes only selected settled history', function () {
    $engine = app(Engine::class);
    $flow = $engine->start(new Workflow([controlled('a')], subjectType: 'document', subjectId: '42', metadata: ['kind' => 'review']));
    $other = $engine->start(new Workflow([controlled('b')], subjectType: 'document', subjectId: '43'));
    expect(WorkflowRecord::forSubject('document', '42')->pluck('id')->all())->toBe([$flow->id]);
    expect(app(History::class)->delete($flow->id))->toBeFalse();
    $engine->cancelWorkflow($flow->id);
    expect(app(History::class)->delete($flow->id))->toBeTrue();
    expect($flow->fresh())->toBeNull()->and($other->fresh()->status)->toBe('running');
    expect(DB::table('scarlett_history')->whereIn('step_id', $other->steps()->pluck('id'))->exists())->toBeTrue();
});

it('uses the individual retry budget to permit a final attempt fallback', function () {
    $flow = app(Engine::class)->start(new Workflow([
        new Node('fallback', ContextStep::class, inputs: ['fail' => true], execution: new ExecutionOptions(attempts: 2, backoffSeconds: [0])),
        controlled('next', stage: 1),
    ]));
    runStep($flow->id, 'fallback');
    expect(statesOf($flow->id))->toBe(['fallback' => 'queued', 'next' => 'pending']);
    runStep($flow->id, 'fallback');
    expect(statesOf($flow->id))->toBe(['fallback' => 'completed', 'next' => 'queued']);
});

it('honours a separate verification budget without repeating the business action', function () {
    $flow = app(Engine::class)->start(new Workflow([
        new Node('verify', ControlledStep::class, inputs: ['name' => 'verify', 'mode' => 'verify'], execution: new ExecutionOptions(verificationAttempts: 2, verificationBackoffSeconds: [6])),
        controlled('rescue', rescues: 'verify'),
    ]));
    runStep($flow->id, 'verify');
    $step = $flow->steps()->where('key', 'verify')->sole();
    expect($step->verifications)->toBe(1)->and($step->available_at->equalTo(now()->addSeconds(6)))->toBeTrue();
    expect($step->finished_at)->toBeNull();
    $this->travel(6)->seconds();
    runStep($flow->id, 'verify');
    expect(statesOf($flow->id))->toBe(['rescue' => 'queued', 'verify' => 'failed']);
    expect(ControlledStep::$actions)->toBe(['verify' => 1]);
    expect($step->fresh()->finished_at->equalTo(now()))->toBeTrue();
});

it('activates durable failure handling after an expired final attempt without reconstructing the failed step', function () {
    $engine = app(Engine::class);
    $flow = $engine->start(new Workflow([
        new Node('action', ControlledStep::class, inputs: ['name' => 'action'], execution: new ExecutionOptions(attempts: 1)),
        controlled('resolver', rescues: 'action'),
    ]));
    $step = $flow->steps()->where('key', 'action')->sole();
    $engine->claim($step->id, $step->attempt);
    $step->fresh()->update(['lease_until' => now()]);
    expect(statesOf($flow->id))->toBe(['action' => 'running', 'resolver' => 'pending']);
    $engine->recover($step->id);
    expect(statesOf($flow->id))->toBe(['action' => 'failed', 'resolver' => 'queued']);
    runStep($flow->id, 'resolver');
    expect(ControlledStep::$actions)->toBe(['resolver' => 1]);
    expect($flow->fresh()->status)->toBe('failed');
});

it('requires explicit permission to revoke an individual running step', function () {
    $engine = app(Engine::class);
    $flow = $engine->start(new Workflow([controlled('a'), controlled('b')]));
    $step = $flow->steps()->where('key', 'a')->sole();
    $engine->claim($step->id, $step->attempt);
    expect($engine->cancel($step->id))->toBeFalse();
    expect($engine->cancel($step->id, includingRunning: true))->toBeTrue();
    expect(statesOf($flow->id))->toBe(['a' => 'cancelled', 'b' => 'queued']);
    expect($engine->heartbeat($step->id, $step->attempt))->toBeFalse();
});

it('does not allow consumer input to impersonate the execution context', function () {
    expect(fn () => app(Engine::class)->start(new Workflow([
        new Node('context', ContextStep::class, inputs: ['context' => null]),
    ])))->toThrow(InvalidArgumentException::class, 'supplied by the engine');
    Queue::assertNothingPushed();
});

it('rejects incomplete subjects and non-json metadata before admission', function (?string $type, ?string $id, array $metadata) {
    expect(fn () => app(Engine::class)->start(new Workflow([controlled('a')], subjectType: $type, subjectId: $id, metadata: $metadata)))
        ->toThrow(InvalidArgumentException::class);
    Queue::assertNothingPushed();
})->with([
    ['record', null, []],
    [null, '1', []],
    ['', '1', []],
    [null, null, ['object' => new stdClass]],
]);
