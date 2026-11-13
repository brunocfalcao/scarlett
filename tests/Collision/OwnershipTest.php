<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Scarlett\Definition\Workflow;
use Scarlett\Engine;
use Scarlett\Models\WorkflowRecord;
use Scarlett\States\Completed;
use Scarlett\Tests\Collision\Workers;

it('admits exactly one of two concurrent workers for the same attempt', function () {
    $flow = app(Engine::class)->start(new Workflow([controlled('a')]));
    $step = $flow->steps()->sole();
    expect((string) $step->state)->toBe('queued');
    $operation = ['operation' => 'claim', 'step' => $step->id, 'attempt' => $step->attempt];
    $results = Workers::race($flow->id, [$operation, $operation]);
    expect(array_sum(array_column($results, 'result')))->toBe(1);
    expect((string) $step->fresh()->state)->toBe('running');
});
it('serializes cancellation against a worker start', function () {
    $flow = app(Engine::class)->start(new Workflow([controlled('a')]));
    $step = $flow->steps()->sole();
    $base = ['step' => $step->id, 'attempt' => $step->attempt];
    $results = Workers::race($flow->id, [['operation' => 'claim'] + $base, ['operation' => 'cancel'] + $base]);
    expect(array_sum(array_column($results, 'result')))->toBe(1);
    expect((string) $step->fresh()->state)->toBe($results[0]['result'] ? 'running' : 'cancelled');
});
it('releases a parallel successor only once under simultaneous completion', function () {
    $engine = app(Engine::class);
    $flow = $engine->start(new Workflow([controlled('a'), controlled('b'), controlled('next', stage: 1)]));
    $operations = [];
    foreach ($flow->steps()->whereIn('key', ['a', 'b'])->get() as $step) {
        $engine->claim($step->id, $step->attempt);
        $engine->saveAction($step->id, $step->attempt, $step->key, Completed::class);
        $operations[] = ['operation' => 'finish', 'step' => $step->id, 'attempt' => $step->attempt];
    }
    expect(statesOf($flow->id)['next'])->toBe('pending');
    $results = Workers::race($flow->id, $operations);
    expect(array_sum(array_column($results, 'result')))->toBe(2);
    expect(statesOf($flow->id))->toBe(['a' => 'completed', 'b' => 'completed', 'next' => 'queued']);
    $next = $flow->steps()->where('key', 'next')->sole();
    expect(DB::table('scarlett_deliveries')->where('step_id', $next->id)->count())->toBe(1);
});
it('rejects an expired worker while recovery creates a fresh attempt', function () {
    $engine = app(Engine::class);
    $flow = $engine->start(new Workflow([controlled('a')]));
    $step = $flow->steps()->sole();
    $engine->claim($step->id, $step->attempt);
    $step->fresh()->update(['lease_until' => now()->subSecond()]);
    $base = ['step' => $step->id, 'attempt' => $step->attempt];
    $results = Workers::race($flow->id, [['operation' => 'recover'] + $base, ['operation' => 'old-outcome'] + $base]);
    expect(array_column($results, 'result'))->toBe([true, false]);
    expect((string) $step->fresh()->state)->toBe('queued');
    expect($step->fresh()->failures)->toBe(1)->and($step->fresh()->attempt)->not->toBe($step->attempt);
});
it('serializes admission against cooldown without partially starting a held workflow', function () {
    $results = Workers::race('', [['operation' => 'admit'], ['operation' => 'cooldown']], 'scarlett_control');
    $flow = WorkflowRecord::findOrFail($results[0]['result']);
    expect($results[1]['result'])->toBeTrue();
    expect($flow->status)->toBeIn(['held', 'running']);
    expect((string) $flow->steps()->sole()->state)->toBe($flow->status === 'held' ? 'pending' : 'queued');
    expect($flow->started_at === null)->toBe($flow->status === 'held');
    $next = app(Engine::class)->start(new Workflow([controlled('next')]));
    expect($next->status)->toBe('held');
});
