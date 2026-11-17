<?php

declare(strict_types=1);

/**
 * Races claims, cancellation, completion, recovery, admission, and guarded writes.
 *
 * Separate PHP processes compete on the same saved workflow or admission lock.
 * Workers::race proves overlap before releasing the parent lock. Tokens, delivery
 * counts, and consumer rows show which operation won and whether an outdated worker
 * was rejected; merely checking eventual workflow status would miss duplicate work.
 */

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Scarlett\Definition\Workflow;
use Scarlett\Engine;
use Scarlett\Models\WorkflowRecord;
use Scarlett\States\Completed;
use Scarlett\Tests\Collision\Environment;
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

it('serializes consumer database writes with workflow revocation and rejects every later write', function () {
    Schema::create('consumer_records', function (Blueprint $table): void {
        $table->uuid('id')->primary();
        $table->integer('writes')->default(0);
    });
    $flow = app(Engine::class)->start(new Workflow([controlled('a')]));
    $record = (string) Str::uuid();
    DB::table('consumer_records')->insert(['id' => $record, 'writes' => 0]);
    $step = $flow->steps()->sole();
    app(Engine::class)->claim($step->id, $step->attempt);
    expect(DB::table('consumer_records')->where('id', $record)->value('writes'))->toBe(0);
    $base = ['step' => $step->id, 'attempt' => $step->attempt, 'workflow' => $flow->id, 'record' => $record];
    $results = Workers::race($flow->id, [['operation' => 'write'] + $base, ['operation' => 'revoke'] + $base]);
    expect($results[1]['result'])->toBeTrue();
    expect(DB::table('consumer_records')->where('id', $record)->value('writes'))->toBe((int) $results[0]['result']);
    expect($flow->fresh()->status)->toBe('cancelled');
    expect(app(Engine::class)->whileCurrent($step->id, $step->attempt, fn () => DB::table('consumer_records')->where('id', $record)->increment('writes')))->toBeFalse();
    expect(DB::table('consumer_records')->where('id', $record)->value('writes'))->toBe((int) $results[0]['result']);
});

it('fences conflicting admission while creating a workflow', function () {
    DB::beginTransaction();
    try {
        $flow = app(Engine::class)->start(new Workflow([controlled('shared-gate')]));
        if (Environment::driver() === 'mysql') {
            $locks = DB::select('SELECT lock_mode FROM performance_schema.data_locks WHERE object_schema = ? AND object_name = ? AND lock_type = ? AND lock_data = ? LIMIT 10', [Environment::DATABASE, 'scarlett_control', 'RECORD', "'admission'"]);
            expect(array_column($locks, 'lock_mode'))->toBe(['S,REC_NOT_GAP']);
        } else {
            config(['database.connections.probe' => config('database.connections.'.Environment::driver())]);
            config(['database.connections.probe.busy_timeout' => 1]);
            $probe = DB::connection('probe');
            if (Environment::driver() === 'pgsql') {
                $probe->statement("SET lock_timeout = '50ms'");
                expect($probe->table('scarlett_control')->where('id', 'admission')->sharedLock()->first()?->id)->toBe('admission');
                expect(fn () => $probe->table('scarlett_control')->where('id', 'admission')->lockForUpdate()->first())->toThrow(PDOException::class);
            } else {
                expect(fn () => $probe->beginTransaction())->toThrow(PDOException::class, 'database is locked');
            }
        }
        expect($flow->status)->toBe('running');
    } finally {
        DB::rollBack();
        DB::purge('probe');
    }
});
