<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Scarlett\Definition\Node;
use Scarlett\Definition\Workflow;
use Scarlett\Engine;
use Scarlett\Tests\Collision\Environment;
use Scarlett\Tests\Fixtures\EchoStep;

it('upgrades an existing installation and leaves repeated migrations and workflow data untouched', function () {
    $flow = app(Engine::class)->start(new Workflow([controlled('existing')]));
    $step = $flow->steps()->sole();
    $this->artisan('migrate:rollback', ['--step' => collect(glob(__DIR__.'/../../database/migrations/*.php'))->filter(fn ($path) => basename($path) >= '2026_11_13_000002_add_consumer_execution_options.php')->count(), '--force' => true])->assertExitCode(0);
    expect(Schema::hasColumn('scarlett_steps', 'not_before'))->toBeFalse();
    expect(DB::table('scarlett_steps')->where('id', $step->id)->value('attempt'))->toBe($step->attempt);
    $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
    expect(Schema::hasColumn('scarlett_steps', 'not_before'))->toBeTrue();
    expect($step->fresh()->execution)->toBeNull();
    $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
    runStep($flow->id, 'existing');
    expect($flow->fresh()->status)->toBe('completed');
});

it('keeps case accent and trailing-space keys distinct', function () {
    $keys = ['Review', 'review', 'cafe', 'café', 'tail', 'tail '];
    $flow = app(Engine::class)->start(new Workflow(array_map(fn ($key) => controlled($key), $keys)));
    foreach ($keys as $key) {
        runStep($flow->id, $key);
    }
    expect($flow->fresh()->status)->toBe('completed');
    expect($flow->steps()->pluck('key')->all())->toEqualCanonicalizing($keys);
});

it('stores future scheduled dates beyond 2038', function () {
    $when = Carbon::parse('2040-01-01 12:00:00', config('app.timezone'));
    $flow = app(Engine::class)->start(new Workflow([
        new Node('future', EchoStep::class, notBefore: $when),
    ]));
    $step = $flow->steps()->sole();
    expect($step->not_before->toDateTimeString())->toBe('2040-01-01 12:00:00');
    expect($step->available_at->toDateTimeString())->toBe('2040-01-01 12:00:00');
    expect(DB::table('scarlett_deliveries')->where('step_id', $step->id)->value('available_at'))->toBe('2040-01-01 12:00:00');
    $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
    expect($step->fresh()->attempt)->toBe($step->attempt);
});

it('preserves incompatible records when rolling back database-specific storage changes', function () {
    $flow = app(Engine::class)->start(new Workflow([controlled('Case'), controlled('case')]));
    $keysMigration = require __DIR__.'/../../database/migrations/2026_11_13_000003_preserve_exact_step_keys.php';
    if (Environment::driver() === 'mysql') {
        expect(fn () => $keysMigration->down())->toThrow(QueryException::class);
    } else {
        $keysMigration->down();
    }
    expect($flow->steps()->pluck('key')->all())->toEqualCanonicalizing(['Case', 'case']);
    $step = $flow->steps()->where('key', 'Case')->sole();
    $step->update(['not_before' => '2040-01-01 00:00:00']);
    $datesMigration = require __DIR__.'/../../database/migrations/2026_11_13_000004_expand_scheduled_date_range.php';
    if (Environment::driver() === 'mysql') {
        expect(fn () => $datesMigration->down())->toThrow(RuntimeException::class, 'storage range');
    } else {
        $datesMigration->down();
    }
    expect($step->fresh()->not_before->year)->toBe(2040);
});
