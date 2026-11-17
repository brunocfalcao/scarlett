<?php

declare(strict_types=1);

/**
 * Checks that mutation paths see committed outcomes despite older consumer reads.
 *
 * Open an outer transaction, establish its view, then commit a competing operation
 * through a separate connection. MySQL can retain the old value for an ordinary read;
 * engine locking reads must still use current state. SQLite serializes writers, so
 * Environment::concurrentCommit adapts transaction order without claiming identical
 * snapshot semantics across drivers. Cleanup restores the default connection.
 */

use Illuminate\Support\Facades\DB;
use Scarlett\Definition\Node;
use Scarlett\Definition\Workflow;
use Scarlett\Engine;
use Scarlett\Execution\Decision;
use Scarlett\Failures\Coordinator;
use Scarlett\Failures\Status;
use Scarlett\History;
use Scarlett\States\Completed;
use Scarlett\Tests\Collision\Environment;
use Scarlett\Tests\Fixtures\FailureAwareStep;

afterEach(function () {
    DB::setDefaultConnection(Environment::driver());
    while (DB::transactionLevel() > 0) {
        DB::rollBack();
    }
    DB::purge('concurrent');
});

it('uses current sibling outcomes to advance a join or cascade inside a consumer transaction', function (bool $recovery) {
    $engine = app(Engine::class);
    $nodes = $recovery ? [
        controlled('parent'), controlled('child', 'fail', parent: 'parent'),
        controlled('a', rescues: 'child'), controlled('b', rescues: 'child'), controlled('next', rescues: 'parent'),
    ] : [controlled('a'), controlled('b'), controlled('next', stage: 1)];
    $flow = $engine->start(new Workflow($nodes));
    $unrelated = $engine->start(new Workflow([controlled('unrelated')]));
    if ($recovery) {
        runStep($flow->id, 'parent');
        runStep($flow->id, 'child');
    }
    $steps = $flow->steps()->get()->keyBy('key');
    foreach (['a', 'b'] as $key) {
        $engine->claim($steps[$key]->id, $steps[$key]->attempt);
        $engine->saveAction($steps[$key]->id, $steps[$key]->attempt, null, Completed::class);
    }
    config(['database.connections.concurrent' => config('database.connections.'.Environment::driver())]);
    DB::beginTransaction();
    expect((string) $steps['a']->fresh()->state)->toBe('running');
    Environment::concurrentCommit(fn () => expect($engine->finish($steps['a']->id, $steps['a']->attempt))->toBeTrue());
    expect((string) $steps['a']->fresh()->state)->toBe(Environment::driver() === 'mysql' ? 'running' : 'completed');
    expect($engine->finish($steps['b']->id, $steps['b']->attempt))->toBeTrue();
    DB::commit();
    expect(statesOf($flow->id)['next'])->toBe('queued')
        ->and(statesOf($unrelated->id))->toBe(['unrelated' => 'queued']);
    runStep($flow->id, 'next');
    expect($flow->fresh()->status)->toBe($recovery ? 'failed' : 'completed');
})->with([false, true]);

it('uses current failure hook outcomes for parent admission and workflow settlement', function () {
    $engine = app(Engine::class);
    $coordinator = app(Coordinator::class);
    $flow = $engine->start(new Workflow([
        new Node('parent', FailureAwareStep::class, inputs: ['name' => 'parent']),
        new Node('a', FailureAwareStep::class, parent: 'parent', inputs: ['name' => 'a']),
        new Node('b', FailureAwareStep::class, parent: 'parent', inputs: ['name' => 'b']),
    ]));
    runStep($flow->id, 'parent');
    $steps = $flow->steps()->get()->keyBy('key');
    foreach (['a', 'b'] as $key) {
        $engine->claim($steps[$key]->id, $steps[$key]->attempt);
    }
    foreach (['a', 'b'] as $key) {
        $engine->decide($steps[$key]->id, $steps[$key]->attempt, Decision::fail());
    }
    $failures = $flow->failures()->get()->keyBy('step_id');
    $a = $failures[$steps['a']->id];
    $b = $failures[$steps['b']->id];
    $parent = $failures[$steps['parent']->id];
    $coordinator->claim($a->id, $a->attempt);
    $coordinator->claim($b->id, $b->attempt);
    config(['database.connections.concurrent' => config('database.connections.'.Environment::driver())]);
    DB::beginTransaction();
    expect($a->fresh()->status)->toBe(Status::Running)->and($parent->fresh()->status)->toBe(Status::Pending);
    Environment::concurrentCommit(fn () => expect($coordinator->complete($a->id, $a->attempt))->toBeTrue());
    expect($coordinator->complete($b->id, $b->attempt))->toBeTrue();
    $currentParent = $parent->fresh();
    expect($currentParent->status)->toBe(Status::Queued);
    $coordinator->claim($currentParent->id, $currentParent->attempt);
    expect($coordinator->complete($currentParent->id, $currentParent->attempt))->toBeTrue();
    DB::commit();
    expect($flow->fresh()->status)->toBe('failed')
        ->and($a->fresh()->status)->toBe(Status::Completed)
        ->and($b->fresh()->status)->toBe(Status::Completed)
        ->and($parent->fresh()->status)->toBe(Status::Completed);
});

it('preserves a concurrently completed step when cancelling from a consumer transaction', function () {
    $engine = app(Engine::class);
    $flow = $engine->start(new Workflow([controlled('a'), controlled('b')]));
    $steps = $flow->steps()->get()->keyBy('key');
    foreach (['a', 'b'] as $key) {
        $engine->claim($steps[$key]->id, $steps[$key]->attempt);
        $engine->saveAction($steps[$key]->id, $steps[$key]->attempt, null, Completed::class);
    }
    config(['database.connections.concurrent' => config('database.connections.'.Environment::driver())]);
    DB::beginTransaction();
    expect((string) $steps['a']->fresh()->state)->toBe('running');
    Environment::concurrentCommit(fn () => $engine->finish($steps['a']->id, $steps['a']->attempt));
    expect($engine->cancelWorkflow($flow->id))->toBeTrue();
    DB::commit();
    expect(statesOf($flow->id))->toBe(['a' => 'completed', 'b' => 'cancelled'])
        ->and($flow->fresh()->status)->toBe('cancelled');
});

it('archives settled work after a failure hook completes in another connection', function () {
    $engine = app(Engine::class);
    $coordinator = app(Coordinator::class);
    $flow = $engine->start(new Workflow([
        new Node('action', FailureAwareStep::class, inputs: ['name' => 'action', 'mode' => 'fail']),
    ]));
    runStep($flow->id, 'action');
    $failure = $flow->failures()->sole();
    $coordinator->claim($failure->id, $failure->attempt);
    config(['database.connections.concurrent' => config('database.connections.'.Environment::driver())]);
    DB::beginTransaction();
    expect($failure->fresh()->status)->toBe(Status::Running);
    Environment::concurrentCommit(fn () => $coordinator->complete($failure->id, $failure->attempt));
    expect(app(History::class)->archive($flow->id))->toBeTrue();
    DB::commit();
    expect($flow->fresh()->status)->toBe('failed')
        ->and($flow->fresh()->archived_at?->isToday())->toBeTrue();
});
