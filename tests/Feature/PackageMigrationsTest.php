<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Scarlett\Definition\Workflow;
use Scarlett\Engine;

it('loads all package migrations through the provider and makes repeated migrate a no-op', function () {
    expect(Schema::hasColumns('scarlett_steps', ['execution', 'not_before', 'started_at', 'finished_at']))->toBeTrue();
    $migrations = DB::table('migrations')->orderBy('migration')->pluck('migration')->all();
    expect($migrations)->toBe(array_map(fn ($path) => basename($path, '.php'), glob(__DIR__.'/../../database/migrations/*.php')));
    $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
    expect(DB::table('migrations')->orderBy('migration')->pluck('migration')->all())->toBe($migrations);
});

it('upgrades and rolls back the additive schema without losing existing workflows', function () {
    Queue::fake();
    $flow = app(Engine::class)->start(new Workflow([controlled('existing')]));
    $step = $flow->steps()->sole();
    $attempt = $step->attempt;
    expect((string) $step->state)->toBe('queued');
    $this->artisan('migrate:rollback', ['--step' => collect(glob(__DIR__.'/../../database/migrations/*.php'))->filter(fn ($path) => basename($path) >= '2026_11_13_000002_add_consumer_execution_options.php')->count(), '--force' => true])->assertExitCode(0);
    expect(Schema::hasColumn('scarlett_steps', 'execution'))->toBeFalse();
    expect(DB::table('scarlett_steps')->where('id', $step->id)->value('attempt'))->toBe($attempt);
    $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
    expect($step->fresh()->execution)->toBeNull();
    expect($step->fresh()->executionOptions()['retry']['attempts'])->toBe(config('scarlett.retry.attempts'));
    runStep($flow->id, 'existing');
    expect($flow->fresh()->status)->toBe('completed');
    $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
    expect($step->fresh()->attempt)->toBe($attempt);
});
