<?php

declare(strict_types=1);

use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Scarlett\Definition\Node;
use Scarlett\Definition\Workflow;
use Scarlett\Engine;
use Scarlett\Inputs\FromResult;
use Scarlett\Inputs\PayloadCodec;
use Scarlett\Inputs\Service;
use Scarlett\Tests\Fixtures\Amount;
use Scarlett\Tests\Fixtures\AmountMapper;
use Scarlett\Tests\Fixtures\EchoStep;
use Scarlett\Tests\Fixtures\TypedStep;
use Symfony\Component\Process\Process;

it('round trips a mapped result through a real database queue and independent worker', function () {
    Schema::create('jobs', function (Blueprint $table): void {
        $table->bigIncrements('id');
        $table->string('queue')->index();
        $table->longText('payload');
        $table->unsignedTinyInteger('attempts');
        $table->unsignedInteger('reserved_at')->nullable();
        $table->unsignedInteger('available_at');
        $table->unsignedInteger('created_at');
    });
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
