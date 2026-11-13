<?php

declare(strict_types=1);

use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Queue\TimeoutExceededException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Scarlett\Definition\ExecutionOptions;
use Scarlett\Definition\Node;
use Scarlett\Definition\Workflow;
use Scarlett\Engine;
use Scarlett\Inputs\FromResult;
use Scarlett\Inputs\PayloadCodec;
use Scarlett\Inputs\Service;
use Scarlett\Tests\Fixtures\Amount;
use Scarlett\Tests\Fixtures\AmountMapper;
use Scarlett\Tests\Fixtures\EchoStep;
use Scarlett\Tests\Fixtures\SlowStep;
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
    config(['queue.default' => 'database', 'queue.connections.database.connection' => 'mysql',
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
    config(['queue.default' => 'database', 'queue.connections.database.connection' => 'mysql']);
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
    config(['queue.default' => 'database', 'queue.connections.database.connection' => 'mysql']);
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
    config(['queue.default' => 'database', 'queue.connections.database.connection' => 'mysql']);
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
    DB::table('scarlett_deliveries')->where('step_id', $step->id)->update(['sent_at' => now()->subSeconds(config('scarlett.delivery_retry_seconds'))]);
    app(Engine::class)->resume($flow->id);
    $healthy = new Process([PHP_BINARY, __DIR__.'/queue-worker.php']);
    $healthy->setTimeout(20);
    $healthy->mustRun();
    expect($flow->fresh()->status)->toBe('completed');
    expect(app(PayloadCodec::class)->decode($step->fresh()->result))->toBe('after-transport-failure');
});

it('fails timed out transport work without relying on a cache counter', function () {
    config(['queue.default' => 'database', 'queue.connections.database.connection' => 'mysql']);
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
