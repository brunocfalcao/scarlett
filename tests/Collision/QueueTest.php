<?php

declare(strict_types=1);

/**
 * Exercises the database queue through independent worker processes.
 *
 * A synchronous runner call cannot prove transport serialization, release timing,
 * worker timeout, or fallback behavior after process death. These cases install the
 * queue tables, start actual PHP workers, and inspect committed engine state and
 * stored result types afterward. Injected faults distinguish queue errors from
 * business retries and retain recoverable intent.
 */

use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Queue\TimeoutExceededException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Scarlett\Definition\ExecutionOptions;
use Scarlett\Definition\Node;
use Scarlett\Definition\Workflow;
use Scarlett\Engine;
use Scarlett\Failures\Status;
use Scarlett\Inputs\FromResult;
use Scarlett\Inputs\ModelReference;
use Scarlett\Inputs\PayloadCodec;
use Scarlett\Inputs\Service;
use Scarlett\Tests\Collision\Environment;
use Scarlett\Tests\Fixtures\Amount;
use Scarlett\Tests\Fixtures\AmountMapper;
use Scarlett\Tests\Fixtures\EchoStep;
use Scarlett\Tests\Fixtures\EnumStep;
use Scarlett\Tests\Fixtures\FailureAwareStep;
use Scarlett\Tests\Fixtures\IntPriority;
use Scarlett\Tests\Fixtures\ModelEchoStep;
use Scarlett\Tests\Fixtures\RuntimeParent;
use Scarlett\Tests\Fixtures\SlowFailureAwareStep;
use Scarlett\Tests\Fixtures\SlowStep;
use Scarlett\Tests\Fixtures\SourceRecord;
use Scarlett\Tests\Fixtures\StringStatus;
use Scarlett\Tests\Fixtures\TypedStep;
use Symfony\Component\Process\Exception\ProcessSignaledException;
use Symfony\Component\Process\Process;

beforeEach(function () {
    Schema::create('jobs', function (Blueprint $table): void {
        $table->bigIncrements('id');
        $table->string('queue')->index();
        $table->longText('payload');
        $table->unsignedTinyInteger('attempts');
        $table->unsignedInteger('reserved_at')->nullable();
        $table->unsignedInteger('available_at');
        $table->unsignedInteger('created_at');
    });
});

it('round trips a mapped result through a real database queue and independent worker', function () {
    config(['queue.default' => 'database', 'queue.connections.database.connection' => Environment::driver(),
        'scarlett.payload_mappers' => ['amount-v1' => AmountMapper::class]]);
    $flow = app(Engine::class)->start(new Workflow([
        new Node('source', EchoStep::class, inputs: ['value' => new Amount(123)]),
        new Node('typed', TypedStep::class, stage: 1, inputs: ['amount' => new FromResult('source'), 'settings' => new Service(Repository::class), 'multiplier' => 2]),
    ]));
    expect(statesOf($flow->id))->toBe(['source' => 'queued', 'typed' => 'pending']);
    $payload = DB::table('jobs')->where('queue', 'scarlett')->sole()->payload;
    expect($payload)->not->toContain(Amount::class)->not->toContain(TypedStep::class);
    $worker = new Process([PHP_BINARY, __DIR__.'/queue-worker.php']);
    $worker->setTimeout(20);
    $worker->mustRun();
    expect(statesOf($flow->id))->toBe(['source' => 'completed', 'typed' => 'completed']);
    expect(app(PayloadCodec::class)->decode($flow->steps()->where('key', 'typed')->sole()->result)->minorUnits)->toBe(246);
});

it('keeps delayed jobs in the real queue until due and preserves primitive timeout routing', function () {
    config(['queue.default' => 'database', 'queue.connections.database.connection' => Environment::driver()]);
    $flow = app(Engine::class)->start(new Workflow([
        new Node('delayed', EchoStep::class, inputs: ['value' => 'due-result'], notBefore: now()->addHour(), execution: new ExecutionOptions(workerTimeout: 900, leaseSeconds: 1000)),
    ]));
    $step = $flow->steps()->sole();
    $job = DB::table('jobs')->where('queue', 'scarlett')->sole();
    $payload = json_decode($job->payload, true, flags: JSON_THROW_ON_ERROR);
    expect($payload['timeout'])->toBe(900);
    expect($job->available_at)->toBe($step->not_before->getTimestamp());
    $worker = new Process([PHP_BINARY, __DIR__.'/queue-worker.php']);
    $worker->setTimeout(20);
    $worker->mustRun();
    expect(statesOf($flow->id))->toBe(['delayed' => 'queued']);
    expect(DB::table('jobs')->where('id', $job->id)->value('attempts'))->toBe(0);
    // Move the persisted clock boundary without waiting an hour in the test process.
    $step->update(['not_before' => now()->subSecond(), 'available_at' => now()->subSecond()]);
    DB::table('jobs')->where('id', $job->id)->update(['available_at' => now()->subSecond()->getTimestamp()]);
    $worker->mustRun();
    expect(statesOf($flow->id))->toBe(['delayed' => 'completed']);
    expect(app(PayloadCodec::class)->decode($step->fresh()->result))->toBe('due-result');
    expect(DB::table('jobs')->where('id', $job->id)->exists())->toBeFalse();
});

it('releases an early delivery and executes its later transport attempt without recovery', function () {
    config(['queue.default' => 'database', 'queue.connections.database.connection' => Environment::driver()]);
    $flow = app(Engine::class)->start(new Workflow([
        new Node('early', EchoStep::class, inputs: ['value' => 'once-due'], notBefore: now()->addMinute()),
    ]));
    $step = $flow->steps()->sole();
    DB::table('jobs')->where('queue', 'scarlett')->update(['available_at' => now()->subSecond()->getTimestamp()]);
    $worker = new Process([PHP_BINARY, __DIR__.'/queue-worker.php']);
    $worker->setTimeout(20);
    $worker->mustRun();
    expect(statesOf($flow->id))->toBe(['early' => 'queued']);
    $released = DB::table('jobs')->where('queue', 'scarlett')->sole();
    expect($released->attempts)->toBe(1)->and($released->available_at)->toBe($step->available_at->getTimestamp());
    $step->update(['not_before' => now()->subSecond(), 'available_at' => now()->subSecond()]);
    DB::table('jobs')->where('id', $released->id)->update(['available_at' => now()->subSecond()->getTimestamp()]);
    $worker->mustRun();
    expect($flow->fresh()->status)->toBe('completed');
    expect($step->fresh()->failures)->toBe(0);
});

it('fails unexpected transport errors without a cache and retains recoverable engine intent', function () {
    config(['queue.default' => 'database', 'queue.connections.database.connection' => Environment::driver()]);
    $flow = app(Engine::class)->start(new Workflow([
        new Node('recoverable', EchoStep::class, inputs: ['value' => 'after-transport-failure']),
    ]));
    $step = $flow->steps()->sole();
    $job = DB::table('jobs')->where('queue', 'scarlett')->sole();
    expect($job->attempts)->toBe(0);
    $worker = new Process([PHP_BINARY, __DIR__.'/queue-worker.php', 'lookup-failure']);
    $worker->setTimeout(20);
    $worker->mustRun();
    expect(DB::table('jobs')->where('payload', 'like', '%'.$step->id.'%')->exists())->toBeFalse();
    expect(statesOf($flow->id))->toBe(['recoverable' => 'queued'])->and($step->fresh()->failures)->toBe(0);
    // BUG-003: replay grace now runs from the delivery's due time, so backdate available_at as well as sent_at.
    DB::table('scarlett_deliveries')->where('step_id', $step->id)->update(['sent_at' => now()->subSeconds(config('scarlett.delivery_retry_seconds')), 'available_at' => now()->subSeconds(config('scarlett.delivery_retry_seconds'))]);
    app(Engine::class)->resume($flow->id);
    $healthy = new Process([PHP_BINARY, __DIR__.'/queue-worker.php']);
    $healthy->setTimeout(20);
    $healthy->mustRun();
    expect($flow->fresh()->status)->toBe('completed');
    expect(app(PayloadCodec::class)->decode($step->fresh()->result))->toBe('after-transport-failure');
});

it('fails timed out transport work without relying on a cache counter', function () {
    config(['queue.default' => 'database', 'queue.connections.database.connection' => Environment::driver()]);
    $flow = app(Engine::class)->start(new Workflow([
        new Node('timeout', SlowStep::class, inputs: ['seconds' => 3], execution: new ExecutionOptions(workerTimeout: 1, leaseSeconds: 5)),
    ]));
    $step = $flow->steps()->sole();
    expect(statesOf($flow->id))->toBe(['timeout' => 'queued']);
    $worker = new Process([PHP_BINARY, __DIR__.'/queue-worker.php', 'timeout']);
    $worker->setTimeout(20);
    try {
        $worker->run();
    } catch (ProcessSignaledException) {
        expect($worker->getTermSignal())->toBe(SIGKILL);
    }
    expect($worker->getOutput())->toContain(TimeoutExceededException::class);
    expect(DB::table('jobs')->where('payload', 'like', '%'.$step->id.'%')->exists())->toBeFalse();
    expect((string) $step->fresh()->state)->toBe('running')->and($step->fresh()->action_completed)->toBeFalse();
});

it('delivers terminal failure hooks through an independent database queue worker', function () {
    config(['queue.default' => 'database', 'queue.connections.database.connection' => Environment::driver()]);
    $flow = app(Engine::class)->start(new Workflow([
        new Node('action', FailureAwareStep::class, inputs: ['name' => 'action', 'mode' => 'fail']),
    ]));
    expect($flow->failures()->exists())->toBeFalse();
    $worker = new Process([PHP_BINARY, __DIR__.'/queue-worker.php']);
    $worker->setTimeout(20);
    $worker->mustRun();
    expect($flow->fresh()->status)->toBe('failed')
        ->and($flow->failures()->sole()->status)->toBe(Status::Completed)
        ->and($flow->fresh()->metadata['failures'][0]['reason'])->toBe('A useful failure reason.');
});

it('recovers terminal failure effects after real action or handler worker timeouts', function (string $mode) {
    config(['queue.default' => 'database', 'queue.connections.database.connection' => Environment::driver()]);
    $flow = app(Engine::class)->start(new Workflow([
        new Node('timeout', SlowFailureAwareStep::class,
            inputs: ['name' => 'timeout', 'mode' => $mode],
            execution: new ExecutionOptions(workerTimeout: 1, leaseSeconds: 5, attempts: 1)),
    ]));
    $worker = new Process([PHP_BINARY, __DIR__.'/queue-worker.php', 'hook-timeout']);
    $worker->setTimeout(20);
    try {
        $worker->run();
    } catch (ProcessSignaledException) {
        expect($worker->getTermSignal())->toBe(SIGKILL);
    }
    expect($worker->getOutput())->toContain(TimeoutExceededException::class);
    if ($mode === 'slow-hook') {
        $failure = $flow->failures()->sole();
        expect($failure->status)->toBe(Status::Running);
        $failure->update(['lease_until' => now()->subSecond()]);
        app(Engine::class)->resume($flow->id);
        expect($failure->fresh()->status)->toBe(Status::Failed)
            ->and($failure->fresh()->error)->toBe('Failure execution lease expired.');
    } else {
        $step = $flow->steps()->sole();
        expect((string) $step->state)->toBe('running');
        $step->update(['lease_until' => now()->subSecond()]);
        app(Engine::class)->resume($flow->id);
        $healthy = new Process([PHP_BINARY, __DIR__.'/queue-worker.php']);
        $healthy->setTimeout(20);
        $healthy->mustRun();
        expect($flow->failures()->sole()->status)->toBe(Status::Completed)
            ->and($flow->fresh()->metadata['failures'][0]['cause'])->toBe('attempt_lost');
    }
    expect($flow->fresh()->status)->toBe('failed');
})->with(['slow-action', 'slow-hook']);

it('executes nested runtime backups and parent verification through an independent queue worker', function () {
    config(['queue.default' => 'database', 'queue.connections.database.connection' => Environment::driver()]);
    $flow = app(Engine::class)->start(new Workflow([
        new Node('parent', RuntimeParent::class, inputs: ['depth' => 3]),
        new Node('after', EchoStep::class, stage: 1, inputs: ['value' => new FromResult('parent')]),
    ]));
    expect(statesOf($flow->id))->toBe(['after' => 'pending', 'parent' => 'queued']);
    $worker = new Process([PHP_BINARY, __DIR__.'/queue-worker.php']);
    $worker->setTimeout(20);
    $worker->mustRun();
    expect($flow->fresh()->status)->toBe('completed');
    expect($flow->steps()->count())->toBe(5);
    expect($flow->steps()->get()->map(fn ($s) => (string) $s->state)->unique()->values()->all())->toBe(['completed']);
    expect(app(PayloadCodec::class)->decode($flow->steps()->where('key', 'after')->sole()->result))->toBe('backup result');
});

it('round-trips BackedEnum and ModelReference payloads through an independent queue worker', function () {
    config([
        'queue.default' => 'database',
        'queue.connections.database.connection' => Environment::driver(),
        'scarlett.enums' => ['status' => StringStatus::class, 'priority' => IntPriority::class],
        'scarlett.models' => ['source_record' => SourceRecord::class],
    ]);

    Schema::dropIfExists('source_records');
    Schema::create('source_records', function (Blueprint $table): void {
        $table->id();
        $table->string('name');
    });

    try {
        $record = SourceRecord::create(['name' => 'queued-record']);

        $flow = app(Engine::class)->start(new Workflow([
            new Node('enums', EnumStep::class, inputs: [
                'status' => StringStatus::Pending,
                'priority' => IntPriority::High,
            ]),
            new Node('model', ModelEchoStep::class, stage: 1, inputs: ['record' => new ModelReference('source_record', $record->id)]),
        ]));

        $worker = new Process([PHP_BINARY, __DIR__.'/queue-worker.php']);
        $worker->setTimeout(20);
        $worker->mustRun();

        $codec = app(PayloadCodec::class);
        $enumStep = $flow->steps()->where('key', 'enums')->sole();
        $modelStep = $flow->steps()->where('key', 'model')->sole();
        $decodedModel = $codec->decode($modelStep->result);

        expect($flow->fresh()->status)->toBe('completed')
            ->and($codec->decode($enumStep->result))->toBe([
                'status' => StringStatus::Pending,
                'priority' => IntPriority::High,
            ])
            ->and($decodedModel)->toBeInstanceOf(SourceRecord::class)
            ->and($decodedModel->is($record))->toBeTrue()
            ->and($decodedModel->name)->toBe('queued-record');
    } finally {
        Schema::dropIfExists('source_records');
    }
});
