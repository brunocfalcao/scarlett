<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Scarlett\Definition\SubjectSlot;
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

it('adds query indexes on fresh installs and preserves data through upgrade repeat and rollback', function () {
    $indexes = [
        'scarlett_workflows' => [
            'scarlett_workflows_created_lookup' => ['created_at', 'id'],
            'scarlett_workflows_retention_lookup' => ['archived_at', 'finished_at', 'id'],
        ],
        'scarlett_steps' => [
            'scarlett_steps_parent_lookup' => ['workflow_id', 'parent_key'],
            'scarlett_steps_rescue_lookup' => ['workflow_id', 'rescues_key'],
        ],
        'scarlett_deliveries' => ['scarlett_deliveries_step_sent_lookup' => ['step_id', 'sent_at']],
        'scarlett_history' => ['scarlett_history_step_created_lookup' => ['step_id', 'created_at', 'id']],
        'scarlett_failures' => ['scarlett_failures_workflow_status_lookup' => ['workflow_id', 'status']],
        'scarlett_subject_slots' => ['scarlett_slots_subject_lookup' => DB::getDriverName() === 'pgsql' ? ['subject_id'] : ['subject_id', 'subject_type']],
    ];
    $assertIndexes = function (bool $installed) use ($indexes): void {
        foreach ($indexes as $table => $expected) {
            $actual = collect(Schema::getIndexes($table))->keyBy('name');
            foreach ($expected as $name => $columns) {
                expect($actual->has($name))->toBe($installed);
                if ($installed) {
                    expect($actual[$name]['columns'])->toBe($columns)
                        ->and($actual[$name]['unique'])->toBeFalse();
                }
            }
        }
    };
    $assertIndexes(true);
    $this->artisan('migrate:rollback', ['--step' => 1, '--force' => true])->assertExitCode(0);
    $assertIndexes(false);

    Queue::fake();
    $flow = app(Engine::class)->start(new Workflow([controlled('existing')]));
    $step = $flow->steps()->sole();
    DB::table('scarlett_history')->insert(['step_id' => $step->id, 'from_state' => 'pending', 'to_state' => 'queued', 'reason' => 'Retained history', 'created_at' => now()]);
    DB::table('scarlett_failures')->insert(['id' => (string) Str::uuid(), 'workflow_id' => $flow->id, 'step_id' => $step->id, 'cause' => 'attempt_lost', 'snapshot' => '{}', 'status' => 'completed', 'created_at' => now(), 'updated_at' => now()]);
    // Unbounded text identities and shared prefixes must retain their exact lookup semantics.
    $prefix = implode('', array_map(fn () => bin2hex(random_bytes(32)), range(1, 80)));
    $subjectIds = [$prefix.'first', $prefix.'second'];
    foreach ($subjectIds as $subjectId) {
        $slot = new SubjectSlot('example', $subjectId, 'current');
        DB::table('scarlett_subject_slots')->insert(['id' => $slot->id(), 'subject_type' => $slot->subjectType, 'subject_id' => $subjectId, 'name' => $slot->name, 'workflow_id' => $flow->id]);
    }
    $snapshot = function (): array {
        $result = [];
        foreach (['scarlett_workflows', 'scarlett_steps', 'scarlett_deliveries', 'scarlett_history', 'scarlett_failures', 'scarlett_subject_slots', 'scarlett_control'] as $table) {
            $result[$table] = DB::table($table)->orderBy('id')->limit(10)->get()->map(fn ($row) => (array) $row)->all();
        }

        return $result;
    };
    $before = $snapshot();
    foreach ([true, false, true] as $install) {
        $this->artisan($install ? 'migrate' : 'migrate:rollback', $install ? ['--force' => true] : ['--step' => 1, '--force' => true])->assertExitCode(0);
        if ($install) {
            // Also cover resuming partially applied non-transactional MySQL DDL.
            (require __DIR__.'/../../database/migrations/2026_11_14_000008_add_query_indexes.php')->up();
            $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        }
        $assertIndexes($install);
        expect($snapshot())->toBe($before);
        foreach ($subjectIds as $subjectId) {
            expect(DB::table('scarlett_subject_slots')->where('subject_type', 'example')->where('subject_id', $subjectId)->limit(2)->pluck('subject_id')->all())->toBe([$subjectId]);
        }
    }
});
