<?php

declare(strict_types=1);

/**
 * Races the last outcomes that can settle a nested parent or release its rescue.
 *
 * Each case saves action results before competing finish calls, isolating the race
 * to durable outcome handling. Repeated rounds vary database scheduling. Count the
 * new delivery and inspect every affected branch so a parent cannot complete early,
 * queue recovery twice, or accept a child whose authority was revoked.
 */

use Illuminate\Support\Facades\DB;
use Scarlett\Definition\Workflow;
use Scarlett\Engine;
use Scarlett\States\Completed;
use Scarlett\Tests\Collision\Workers;

it('settles nested parents once when the last leaves finish concurrently', function (int $round) {
    $engine = app(Engine::class);
    $flow = $engine->start(new Workflow([
        controlled('root'), controlled('left', parent: 'root'), controlled('right', parent: 'root'),
        controlled('a', parent: 'left'), controlled('b', parent: 'right'), controlled('after', stage: 1),
    ], metadata: ['round' => $round]));
    foreach (['root', 'left', 'right'] as $key) {
        runStep($flow->id, $key);
    }
    $operations = [];
    foreach (['a', 'b'] as $key) {
        $step = $flow->steps()->where('key', $key)->sole();
        $engine->claim($step->id, $step->attempt);
        $engine->saveAction($step->id, $step->attempt, $key, Completed::class);
        $operations[] = ['operation' => 'finish', 'step' => $step->id, 'attempt' => $step->attempt];
    }
    expect(statesOf($flow->id))->toBe([
        'a' => 'running', 'after' => 'pending', 'b' => 'running',
        'left' => 'waitingforchildren', 'right' => 'waitingforchildren', 'root' => 'waitingforchildren',
    ]);
    $results = Workers::race($flow->id, $operations);
    expect(array_column($results, 'result'))->toBe([true, true]);
    expect(statesOf($flow->id))->toBe([
        'a' => 'completed', 'after' => 'queued', 'b' => 'completed',
        'left' => 'completed', 'right' => 'completed', 'root' => 'completed',
    ]);
    foreach (['root', 'left', 'right'] as $key) {
        $parent = $flow->steps()->where('key', $key)->sole();
        expect(DB::table('scarlett_history')->where('step_id', $parent->id)->where('to_state', 'completed')->count())->toBe(1);
    }
    $after = $flow->steps()->where('key', 'after')->sole();
    expect(DB::table('scarlett_deliveries')->where('step_id', $after->id)->count())->toBe(1);
    runStep($flow->id, 'after');
    expect($flow->fresh()->status)->toBe('completed');
})->with([1, 2, 3]);

it('admits ancestor recovery once when the last parallel child rescues finish concurrently', function (int $round) {
    $engine = app(Engine::class);
    $flow = $engine->start(new Workflow([
        controlled('parent'), controlled('child', 'fail', parent: 'parent'),
        controlled('a', rescues: 'child'), controlled('b', rescues: 'child'),
        controlled('parent-rescue', rescues: 'parent'), controlled('after', stage: 1),
    ], metadata: ['round' => $round]));
    runStep($flow->id, 'parent');
    runStep($flow->id, 'child');
    $operations = [];
    foreach (['a', 'b'] as $key) {
        $step = $flow->steps()->where('key', $key)->sole();
        $engine->claim($step->id, $step->attempt);
        $engine->saveAction($step->id, $step->attempt, null, Completed::class);
        $operations[] = ['operation' => 'finish', 'step' => $step->id, 'attempt' => $step->attempt];
    }
    expect(statesOf($flow->id))->toBe([
        'a' => 'running', 'after' => 'pending', 'b' => 'running', 'child' => 'failed',
        'parent' => 'waitingforchildren', 'parent-rescue' => 'pending',
    ]);
    $results = Workers::race($flow->id, $operations);
    expect(array_column($results, 'result'))->toBe([true, true]);
    expect(statesOf($flow->id))->toBe([
        'a' => 'completed', 'after' => 'cancelled', 'b' => 'completed', 'child' => 'failed',
        'parent' => 'failed', 'parent-rescue' => 'queued',
    ]);
    $parent = $flow->steps()->where('key', 'parent')->sole();
    $rescue = $flow->steps()->where('key', 'parent-rescue')->sole();
    expect(DB::table('scarlett_history')->where('step_id', $parent->id)->where('to_state', 'failed')->count())->toBe(1)
        ->and(DB::table('scarlett_deliveries')->where('step_id', $rescue->id)->count())->toBe(1);
    foreach ($operations as $operation) {
        expect($engine->finish($operation['step'], $operation['attempt']))->toBeFalse();
    }
    runStep($flow->id, 'parent-rescue');
    expect($flow->fresh()->status)->toBe('failed');
})->with([1, 2, 3]);

it('never completes the parent when a last-child failure races another child completion', function (int $round) {
    $engine = app(Engine::class);
    $flow = $engine->start(new Workflow([
        controlled('root'), controlled('branch', parent: 'root'),
        controlled('a', parent: 'branch'), controlled('b', parent: 'branch'),
        controlled('after', stage: 1), controlled('rescue', rescues: 'root'),
    ], metadata: ['round' => $round]));
    runStep($flow->id, 'root');
    runStep($flow->id, 'branch');
    $operations = [];
    foreach (['a' => 'finish', 'b' => 'old-outcome'] as $key => $operation) {
        $step = $flow->steps()->where('key', $key)->sole();
        $engine->claim($step->id, $step->attempt);
        $engine->saveAction($step->id, $step->attempt, $key, Completed::class);
        $operations[] = ['operation' => $operation, 'step' => $step->id, 'attempt' => $step->attempt];
    }
    expect(statesOf($flow->id))->toBe([
        'a' => 'running', 'after' => 'pending', 'b' => 'running',
        'branch' => 'waitingforchildren', 'rescue' => 'pending', 'root' => 'waitingforchildren',
    ]);
    $results = Workers::race($flow->id, $operations);
    expect(array_column($results, 'result'))->toBe([true, true]);
    expect(statesOf($flow->id))->toBe([
        'a' => 'completed', 'after' => 'cancelled', 'b' => 'failed',
        'branch' => 'failed', 'rescue' => 'queued', 'root' => 'failed',
    ]);
    foreach (['root', 'branch'] as $key) {
        $parent = $flow->steps()->where('key', $key)->sole();
        expect(DB::table('scarlett_history')->where('step_id', $parent->id)->where('to_state', 'completed')->exists())->toBeFalse();
    }
    $after = $flow->steps()->where('key', 'after')->sole();
    expect(DB::table('scarlett_deliveries')->where('step_id', $after->id)->exists())->toBeFalse();
    runStep($flow->id, 'rescue');
    expect($flow->fresh()->status)->toBe('failed');
})->with([1, 2, 3]);

it('revokes the whole nested tree while its last child tries to finish', function (int $round) {
    $engine = app(Engine::class);
    $flow = $engine->start(new Workflow([
        controlled('root'), controlled('branch', parent: 'root'),
        controlled('leaf', parent: 'branch'), controlled('after', stage: 1),
    ], metadata: ['round' => $round]));
    runStep($flow->id, 'root');
    runStep($flow->id, 'branch');
    $leaf = $flow->steps()->where('key', 'leaf')->sole();
    $engine->claim($leaf->id, $leaf->attempt);
    $engine->saveAction($leaf->id, $leaf->attempt, 'saved', Completed::class);
    expect(statesOf($flow->id))->toBe([
        'after' => 'pending', 'branch' => 'waitingforchildren', 'leaf' => 'running', 'root' => 'waitingforchildren',
    ]);
    $results = Workers::race($flow->id, [
        ['operation' => 'finish', 'step' => $leaf->id, 'attempt' => $leaf->attempt],
        ['operation' => 'revoke', 'workflow' => $flow->id],
    ]);
    expect($results[1]['result'])->toBeTrue();
    $settled = $results[0]['result'] ? 'completed' : 'cancelled';
    expect(statesOf($flow->id))->toBe(['after' => 'cancelled', 'branch' => $settled, 'leaf' => $settled, 'root' => $settled])
        ->and($flow->fresh()->status)->toBe('cancelled')
        ->and($engine->finish($leaf->id, $leaf->attempt))->toBeFalse();
    $after = $flow->steps()->where('key', 'after')->sole();
    expect($engine->claim($after->id, $after->attempt ?? 'unclaimed'))->toBeNull();
})->with([1, 2, 3]);

it('keeps ancestors waiting while recovery replaces an expired child attempt', function (int $round) {
    $engine = app(Engine::class);
    $flow = $engine->start(new Workflow([
        controlled('root'), controlled('branch', parent: 'root'),
        controlled('leaf', parent: 'branch'), controlled('after', stage: 1),
    ], metadata: ['round' => $round]));
    runStep($flow->id, 'root');
    runStep($flow->id, 'branch');
    $leaf = $flow->steps()->where('key', 'leaf')->sole();
    $engine->claim($leaf->id, $leaf->attempt);
    $engine->saveAction($leaf->id, $leaf->attempt, 'saved', Completed::class);
    $leaf->fresh()->update(['lease_until' => now()->subSecond()]);
    expect(statesOf($flow->id)['root'])->toBe('waitingforchildren');
    $base = ['step' => $leaf->id, 'attempt' => $leaf->attempt];
    $results = Workers::race($flow->id, [['operation' => 'recover'] + $base, ['operation' => 'finish'] + $base]);
    expect(array_column($results, 'result'))->toBe([true, false]);
    expect(statesOf($flow->id))->toBe(['after' => 'pending', 'branch' => 'waitingforchildren', 'leaf' => 'queued', 'root' => 'waitingforchildren'])
        ->and($leaf->fresh()->attempt)->not->toBe($leaf->attempt);
    // Recovery of an already saved action uses the separate verification delay.
    $this->travel(5)->seconds();
    runStep($flow->id, 'leaf');
    expect(statesOf($flow->id))->toBe(['after' => 'queued', 'branch' => 'completed', 'leaf' => 'completed', 'root' => 'completed']);
})->with([1, 2, 3]);
