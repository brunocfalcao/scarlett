<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Scarlett\Definition\Node;
use Scarlett\Definition\Subject;
use Scarlett\Definition\SubjectSlot;
use Scarlett\Definition\Workflow;
use Scarlett\Engine;
use Scarlett\Inputs\FromResult;
use Scarlett\Inputs\PayloadCodec;
use Scarlett\Models\WorkflowRecord;
use Scarlett\States\Completed;
use Scarlett\Tests\Collision\Workers;
use Scarlett\Tests\Fixtures\ControlledStep;
use Scarlett\Tests\Fixtures\EchoStep;
use Scarlett\Tests\Fixtures\RuntimeParent;
use Symfony\Component\Process\Process;

function cleanupScaleChaosRedis(string $prefix): void
{
    Redis::purge();
    try {
        $client = new \Redis;
        $client->connect(getenv('REDIS_HOST') ?: '127.0.0.1', (int) (getenv('REDIS_PORT') ?: 6379), 1.5);
        $keys = $client->keys($prefix.'*');
        if (is_array($keys) && $keys !== []) {
            $client->del($keys);
        }
        $client->close();
    } catch (Throwable) {
    }
}

it('serializes a 10-way concurrent SubjectSlot replace stampede across 10 worker processes', function () {
    Schema::dropIfExists('consumer_records');
    Schema::create('consumer_records', function (Blueprint $table): void {
        $table->uuid('id')->primary();
        $table->integer('writes')->default(0);
    });

    $engine = app(Engine::class);
    $subjectId = 'stampede-'.bin2hex(random_bytes(4));
    $slot = new SubjectSlot('consumer', $subjectId, 'current');
    $recordId = (string) Str::uuid();
    DB::table('consumer_records')->insert(['id' => $recordId, 'writes' => 0]);

    $seed = $engine->replace($slot, fn (): Workflow => new Workflow([new Node('seed', EchoStep::class)]));
    $operations = array_fill(0, 10, ['operation' => 'replace', 'subject' => $subjectId, 'record' => $recordId]);

    $results = Workers::race($slot->id(), $operations, 'scarlett_subject_slots');
    $createdIds = array_column($results, 'result');
    $winner = $engine->current($slot);

    expect($createdIds)->toHaveCount(10)
        ->and(array_unique($createdIds))->toHaveCount(10)
        ->and($winner)->not->toBeNull()
        ->and($createdIds)->toContain($winner)
        ->and(DB::table('consumer_records')->where('id', $recordId)->value('writes'))->toBe(10);

    $workflows = WorkflowRecord::where('subject_type', 'consumer')->where('subject_id', $subjectId)->get();
    $running = $workflows->where('status', 'running');
    $cancelled = $workflows->where('status', 'cancelled');

    expect($workflows)->toHaveCount(11)
        ->and($running->pluck('id')->all())->toBe([$winner])
        ->and($cancelled)->toHaveCount(10)
        ->and($seed->fresh()->status)->toBe('cancelled')
        ->and($cancelled->every(fn (WorkflowRecord $flow): bool => $flow->finished_at !== null))->toBeTrue()
        ->and(DB::table('scarlett_subject_slots')->where('id', $slot->id())->value('workflow_id'))->toBe($winner);
});

it('releases a 12-way parallel barrier exactly once under 12-worker simultaneous finish contention', function () {
    $engine = app(Engine::class);
    $nodes = [];
    for ($i = 0; $i < 12; $i++) {
        $nodes[] = controlled("branch-{$i}");
    }
    $nodes[] = controlled('join', stage: 1);

    $flow = $engine->start(new Workflow($nodes));
    $operations = [];
    foreach ($flow->steps()->where('key', '!=', 'join')->orderBy('key')->get() as $step) {
        $engine->claim($step->id, $step->attempt);
        $engine->saveAction($step->id, $step->attempt, $step->key, Completed::class);
        $operations[] = ['operation' => 'finish', 'step' => $step->id, 'attempt' => $step->attempt];
    }

    $results = Workers::race($flow->id, $operations);
    $join = $flow->steps()->where('key', 'join')->sole();

    expect(array_sum(array_column($results, 'result')))->toBe(12)
        ->and((string) $join->state)->toBe('queued')
        ->and(DB::table('scarlett_deliveries')->where('step_id', $join->id)->count())->toBe(1);
});

it('handles 2,000-workflow scale for currentForSubjects, selective recovery, batched retention, and throttle pruning', function () {
    $now = now();
    $old = $now->copy()->subDays(45);
    $workflowRows = [];
    $stepRows = [];
    $slotRows = [];
    $subjectIds = [];

    for ($i = 0; $i < 2000; $i++) {
        $workflowId = (string) Str::uuid();
        $stepId = (string) Str::uuid();

        if ($i < 1000) {
            $status = 'completed';
            $stepState = 'completed';
            $finishedAt = $old;
            $archivedAt = $old;
            $leaseUntil = null;
        } elseif ($i < 1500) {
            $status = 'completed';
            $stepState = 'completed';
            $finishedAt = $old;
            $archivedAt = null;
            $leaseUntil = null;
        } elseif ($i < 1999) {
            $status = 'running';
            $stepState = 'running';
            $finishedAt = null;
            $archivedAt = null;
            $leaseUntil = $now->copy()->addMinutes(10);
        } else {
            $status = 'running';
            $stepState = 'running';
            $finishedAt = null;
            $archivedAt = null;
            $leaseUntil = $now->copy()->subSecond();
            $stalledWorkflowId = $workflowId;
            $stalledStepId = $stepId;
        }

        $workflowRows[] = [
            'id' => $workflowId,
            'status' => $status,
            'subject_type' => $i >= 1499 && $i < 1999 ? 'position' : null,
            'subject_id' => $i >= 1499 && $i < 1999 ? 'pos-'.($i - 1499) : null,
            'metadata' => null,
            'started_at' => $old,
            'finished_at' => $finishedAt,
            'archived_at' => $archivedAt,
            'created_at' => $old,
            'updated_at' => $old,
        ];

        $stepRows[] = [
            'id' => $stepId,
            'workflow_id' => $workflowId,
            'key' => 'work',
            'class' => EchoStep::class,
            'state' => $stepState,
            'parent_key' => null,
            'rescues_key' => null,
            'dependencies' => '[]',
            'inputs' => '[]',
            'execution' => json_encode(['worker_timeout' => 240, 'lease_seconds' => 300, 'retry' => ['attempts' => 3, 'backoff_seconds' => [0]], 'verification' => ['attempts' => 3, 'backoff_seconds' => [0]]], JSON_THROW_ON_ERROR),
            'throttle_reservations' => null,
            'priority' => 0,
            'queue' => 'scarlett',
            'connection' => null,
            'attempt' => (string) Str::uuid(),
            'failures' => 0,
            'verifications' => 0,
            'action_completed' => false,
            'available_at' => $now,
            'lease_until' => $leaseUntil,
            'not_before' => null,
            'started_at' => $old,
            'finished_at' => $finishedAt,
            'created_at' => $old,
            'updated_at' => $old,
        ];

        if ($i >= 1499 && $i < 1999) {
            $subjectId = 'pos-'.($i - 1499);
            $subjectIds[] = $subjectId;
            $slotRows[] = [
                'id' => (new SubjectSlot('position', $subjectId, 'main'))->id(),
                'subject_type' => 'position',
                'subject_id' => $subjectId,
                'name' => 'main',
                'workflow_id' => $workflowId,
            ];
        }
    }

    foreach (array_chunk($workflowRows, 500) as $chunk) {
        DB::table('scarlett_workflows')->insert($chunk);
    }
    foreach (array_chunk($stepRows, 500) as $chunk) {
        DB::table('scarlett_steps')->insert($chunk);
    }
    DB::table('scarlett_subject_slots')->insert($slotRows);

    $throttleRows = [];
    $expiredMs = $now->copy()->subSeconds(5)->getTimestampMs();
    $futureMs = $now->copy()->addMinutes(5)->getTimestampMs();
    for ($i = 0; $i < 1500; $i++) {
        $throttleRows[] = [
            'id' => hash('sha256', 'expired-'.$i),
            'grants' => '[]',
            'max_requests' => 1,
            'per_seconds' => 60,
            'expires_at' => $expiredMs,
        ];
    }
    for ($i = 0; $i < 5; $i++) {
        $throttleRows[] = [
            'id' => hash('sha256', 'active-'.$i),
            'grants' => '[]',
            'max_requests' => 1,
            'per_seconds' => 60,
            'expires_at' => $futureMs,
        ];
    }
    foreach (array_chunk($throttleRows, 500) as $chunk) {
        DB::table('scarlett_throttles')->insert($chunk);
    }

    $engine = app(Engine::class);
    $foundSlots = $engine->currentForSubjects('position', $subjectIds, 'main');
    expect(count($foundSlots))->toBe(500);

    expect($engine->stalled())->toBeTrue();
    Artisan::call('scarlett:recover');
    expect(trim(Artisan::output()))->toBe('Recovered 1 execution(s).')
        ->and($engine->stalled())->toBeFalse()
        ->and((string) DB::table('scarlett_steps')->where('id', $stalledStepId)->value('state'))->toBe('queued')
        ->and(WorkflowRecord::findOrFail($stalledWorkflowId)->status)->toBe('running');

    Artisan::call('scarlett:archive', ['--days' => 30, '--batch' => 250]);
    expect(trim(Artisan::output()))->toBe('Archived 500 workflow(s).');

    Artisan::call('scarlett:purge', ['--days' => 30, '--batch' => 250]);
    expect(trim(Artisan::output()))->toBe('Purged 1000 workflow(s).')
        ->and(DB::table('scarlett_workflows')->count())->toBe(1000);

    Artisan::call('scarlett:prune-throttles', ['--batch' => 500]);
    expect(DB::table('scarlett_throttles')->count())->toBe(5);
});

it('survives multi-worker Redis chaos with ConnectionRelease, cooldown pulses, slot replacements, and subject deletions', function () {
    $prefix = 'scarlett_chaos_'.bin2hex(random_bytes(5)).'_';
    $queue = 'scarlett-chaos-'.bin2hex(random_bytes(3));
    config([
        'database.redis.client' => 'phpredis',
        'database.redis.options.prefix' => $prefix,
        'database.redis.default' => [
            'host' => getenv('REDIS_HOST') ?: '127.0.0.1',
            'password' => getenv('REDIS_PASSWORD') ?: null,
            'port' => (int) (getenv('REDIS_PORT') ?: 6379),
            'database' => 0,
        ],
        'queue.default' => 'redis',
        'queue.connections.redis' => [
            'driver' => 'redis',
            'connection' => 'default',
            'queue' => $queue,
            'retry_after' => 300,
            'block_for' => null,
            'after_commit' => false,
        ],
        'scarlett.queue' => $queue,
        'scarlett.release_database_connection' => true,
    ]);

    $engine = app(Engine::class);
    $runtimeIds = [];
    $replacementIds = [];

    try {
        for ($i = 0; $i < 8; $i++) {
            $runtimeIds[] = $engine->start(new Workflow([
                new Node('root', RuntimeParent::class, queue: $queue, inputs: ['depth' => 1, 'value' => "ok-{$i}"]),
                new Node('after', EchoStep::class, stage: 1, queue: $queue, inputs: ['value' => new FromResult('root')]),
            ]))->id;
        }

        for ($i = 0; $i < 4; $i++) {
            $slot = new SubjectSlot('order', "ord-{$i}", 'primary');
            $engine->replace($slot, fn (): Workflow => new Workflow([
                new Node('first', ControlledStep::class, queue: $queue, inputs: ['name' => 'first']),
            ]));
            $replacementIds["ord-{$i}"] = $engine->replace($slot, fn (): Workflow => new Workflow([
                new Node('second', EchoStep::class, queue: $queue, inputs: ['value' => "replaced-{$i}"]),
            ]))->id;
        }

        $deletedSlot = new SubjectSlot('order', 'ord-delete', 'primary');
        $deleted = $engine->replace($deletedSlot, fn (): Workflow => new Workflow([
            new Node('victim', EchoStep::class, queue: $queue, inputs: ['value' => 'victim']),
        ]));

        $engine->cooldown();
        $held = $engine->start(new Workflow([
            new Node('held-after-pulse', EchoStep::class, queue: $queue, inputs: ['value' => 'pulse-ok']),
        ]));
        expect($held->status)->toBe('held');
        $engine->reopen();

        expect($engine->deleteSubject(new Subject('order', 'ord-delete'), fn (): bool => true))->toBeTrue();

        $workers = [];
        for ($i = 0; $i < 4; $i++) {
            $worker = new Process([PHP_BINARY, __DIR__.'/queue-redis-worker.php', $prefix, 'release', $queue]);
            $worker->setTimeout(40);
            $worker->start();
            $workers[] = $worker;
        }

        foreach ($workers as $worker) {
            $worker->wait();
            expect($worker->isSuccessful())->toBeTrue($worker->getErrorOutput().$worker->getOutput());
        }

        $codec = app(PayloadCodec::class);
        foreach ($runtimeIds as $index => $workflowId) {
            $flow = WorkflowRecord::findOrFail($workflowId);
            expect($flow->status)->toBe('completed')
                ->and($codec->decode($flow->steps()->where('key', 'after')->sole()->result))->toBe("ok-{$index}");
        }

        foreach ($replacementIds as $subjectId => $expectedId) {
            $flow = WorkflowRecord::findOrFail($expectedId);
            expect($flow->status)->toBe('completed')
                ->and($engine->current(new SubjectSlot('order', $subjectId, 'primary')))->toBe($expectedId);
        }

        expect(WorkflowRecord::find($deleted->id))->toBeNull()
            ->and($held->fresh()->status)->toBe('completed');
    } finally {
        cleanupScaleChaosRedis($prefix);
    }
});

it('drains 200 sequential and fan-out steps inside one continuous Redis worker without memory bloat or errors', function () {
    $prefix = 'scarlett_soak_'.bin2hex(random_bytes(5)).'_';
    $queue = 'scarlett-soak-'.bin2hex(random_bytes(3));
    config([
        'database.redis.client' => 'phpredis',
        'database.redis.options.prefix' => $prefix,
        'database.redis.default' => [
            'host' => getenv('REDIS_HOST') ?: '127.0.0.1',
            'password' => getenv('REDIS_PASSWORD') ?: null,
            'port' => (int) (getenv('REDIS_PORT') ?: 6379),
            'database' => 0,
        ],
        'queue.default' => 'redis',
        'queue.connections.redis' => [
            'driver' => 'redis',
            'connection' => 'default',
            'queue' => $queue,
            'retry_after' => 300,
            'block_for' => null,
            'after_commit' => false,
        ],
        'scarlett.queue' => $queue,
    ]);

    try {
        $engine = app(Engine::class);
        $workflowIds = [];
        for ($i = 0; $i < 50; $i++) {
            $workflowIds[] = $engine->start(new Workflow([
                new Node('a', EchoStep::class, queue: $queue, inputs: ['value' => $i]),
                new Node('b', EchoStep::class, queue: $queue, inputs: ['value' => $i * 2]),
                new Node('c', EchoStep::class, stage: 1, queue: $queue, inputs: ['value' => new FromResult('a')]),
                new Node('d', EchoStep::class, stage: 2, queue: $queue, inputs: ['value' => new FromResult('c')]),
            ]))->id;
        }

        $worker = new Process([PHP_BINARY, __DIR__.'/queue-redis-worker.php', $prefix, 'normal', $queue]);
        $worker->setTimeout(60);
        $worker->mustRun();

        $report = json_decode(trim($worker->getOutput()), true, flags: JSON_THROW_ON_ERROR);
        expect($report['exit'])->toBe(0)
            ->and($report['peak_memory'])->toBeLessThan(128 * 1024 * 1024)
            ->and(WorkflowRecord::whereIn('id', $workflowIds)->where('status', 'completed')->count())->toBe(50);

        $sample = WorkflowRecord::findOrFail($workflowIds[49]);
        expect(app(PayloadCodec::class)->decode($sample->steps()->where('key', 'd')->sole()->result))->toBe(49);
    } finally {
        cleanupScaleChaosRedis($prefix);
    }
});
