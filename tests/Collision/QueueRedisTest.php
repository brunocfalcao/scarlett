<?php

declare(strict_types=1);

use Illuminate\Queue\TimeoutExceededException;
use Illuminate\Support\Facades\Redis;
use Scarlett\Definition\ExecutionOptions;
use Scarlett\Definition\Node;
use Scarlett\Definition\Workflow;
use Scarlett\Engine;
use Scarlett\Failures\Status;
use Scarlett\Inputs\FromResult;
use Scarlett\Inputs\PayloadCodec;
use Scarlett\Tests\Fixtures\Amount;
use Scarlett\Tests\Fixtures\AmountMapper;
use Scarlett\Tests\Fixtures\ControlledStep;
use Scarlett\Tests\Fixtures\EchoStep;
use Scarlett\Tests\Fixtures\EnumStep;
use Scarlett\Tests\Fixtures\IntPriority;
use Scarlett\Tests\Fixtures\RateLimitedStep;
use Scarlett\Tests\Fixtures\RuntimeParent;
use Scarlett\Tests\Fixtures\SlowFailureAwareStep;
use Scarlett\Tests\Fixtures\StringStatus;
use Symfony\Component\Process\Exception\ProcessSignaledException;
use Symfony\Component\Process\Process;

function configureRedisQueue(string $prefix, string $queue = 'scarlett', bool $releaseConnection = false): void
{
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
        'scarlett.release_database_connection' => $releaseConnection,
        'scarlett.payload_mappers' => ['amount-v1' => AmountMapper::class],
        'scarlett.enums' => ['status' => StringStatus::class, 'priority' => IntPriority::class],
    ]);
    Redis::purge();
}

function cleanupRedisPrefix(string $prefix): void
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

it('executes nested runtime plans, mapped payloads, and BackedEnums over real Redis queue workers', function (bool $releaseConnection) {
    $prefix = 'scarlett_test_'.bin2hex(random_bytes(5)).'_';
    $queue = 'scarlett-redis-'.bin2hex(random_bytes(3));
    configureRedisQueue($prefix, $queue, $releaseConnection);

    try {
        $flow = app(Engine::class)->start(new Workflow([
            new Node('parent', RuntimeParent::class, queue: $queue, inputs: ['depth' => 2]),
            new Node('enums', EnumStep::class, queue: $queue, inputs: ['status' => StringStatus::Done, 'priority' => IntPriority::High]),
            new Node('amount', EchoStep::class, stage: 1, queue: $queue, inputs: ['value' => new Amount(4250)]),
            new Node('after', EchoStep::class, stage: 1, queue: $queue, inputs: ['value' => new FromResult('parent')]),
        ]));

        $workers = [];
        $mode = $releaseConnection ? 'release' : 'normal';
        for ($i = 0; $i < 3; $i++) {
            $worker = new Process([PHP_BINARY, __DIR__.'/queue-redis-worker.php', $prefix, $mode, $queue]);
            $worker->setTimeout(25);
            $worker->start();
            $workers[] = $worker;
        }

        foreach ($workers as $worker) {
            $worker->wait();
            expect($worker->isSuccessful())->toBeTrue($worker->getErrorOutput().$worker->getOutput());
        }

        $codec = app(PayloadCodec::class);
        expect($flow->fresh()->status)->toBe('completed')
            ->and($codec->decode($flow->steps()->where('key', 'amount')->sole()->result))->toEqual(new Amount(4250))
            ->and($codec->decode($flow->steps()->where('key', 'after')->sole()->result))->toBe('backup result')
            ->and($codec->decode($flow->steps()->where('key', 'enums')->sole()->result))->toBe([
                'status' => StringStatus::Done,
                'priority' => IntPriority::High,
            ]);
    } finally {
        cleanupRedisPrefix($prefix);
    }
})->with([
    'persistent db connection' => [false],
    'released db connection' => [true],
]);

it('holds throttled Redis jobs until due without spending retries', function () {
    $prefix = 'scarlett_test_'.bin2hex(random_bytes(5)).'_';
    $queue = 'scarlett-redis-delay-'.bin2hex(random_bytes(3));
    configureRedisQueue($prefix, $queue);
    config([
        'scarlett.minimum_throttle_seconds' => 1,
        'scarlett.throttles' => [
            'burst' => ['max_requests' => 1, 'per_seconds' => 1],
        ],
    ]);

    $this->freezeSecond();
    $clock = now()->toIso8601String();

    try {
        $engine = app(Engine::class);
        $first = $engine->start(new Workflow([
            new Node('limited', RateLimitedStep::class, queue: $queue, inputs: ['policy' => 'burst', 'account' => 'acct-1'], execution: new ExecutionOptions(attempts: 1, backoffSeconds: [0])),
        ]));

        $initial = new Process([PHP_BINARY, __DIR__.'/queue-redis-worker.php', $prefix, 'normal', $queue, $clock]);
        $initial->setTimeout(20);
        $initial->mustRun();
        expect($first->fresh()->status)->toBe('completed');

        $second = $engine->start(new Workflow([
            new Node('limited', RateLimitedStep::class, queue: $queue, inputs: ['policy' => 'burst', 'account' => 'acct-1'], execution: new ExecutionOptions(attempts: 1, backoffSeconds: [0])),
            new Node('next', ControlledStep::class, stage: 1, queue: $queue, inputs: ['name' => 'next']),
        ]));

        // With the burst quota held by $first, $second must release to Redis queues:<q>:delayed with zero failures.
        $throttledRun = new Process([PHP_BINARY, __DIR__.'/queue-redis-worker.php', $prefix, 'normal', $queue, $clock]);
        $throttledRun->setTimeout(20);
        $throttledRun->mustRun();

        $limitedBefore = $second->steps()->where('key', 'limited')->sole();
        expect($second->fresh()->status)->toBe('running')
            ->and((string) $limitedBefore->state)->toBe('queued')
            ->and($limitedBefore->failures)->toBe(0)
            ->and($limitedBefore->available_at->getTimestamp())->toBe(now()->addSecond()->getTimestamp())
            ->and(Redis::connection()->zcard("queues:{$queue}:delayed"))->toBe(1);

        $throttledRun->mustRun();
        expect($second->steps()->where('key', 'limited')->sole()->getRawOriginal())->toBe($limitedBefore->getRawOriginal())
            ->and(Redis::connection()->zcard("queues:{$queue}:delayed"))->toBe(1);

        $this->travel(1)->seconds();
        $worker = new Process([PHP_BINARY, __DIR__.'/queue-redis-worker.php', $prefix, 'normal', $queue, now()->toIso8601String()]);
        $worker->setTimeout(20);
        $worker->mustRun();

        $limited = $second->steps()->where('key', 'limited')->sole();
        expect($second->fresh()->status)->toBe('completed')
            ->and($limited->failures)->toBe(0)
            ->and(Redis::connection()->zcard("queues:{$queue}:delayed"))->toBe(0)
            ->and((string) $second->steps()->where('key', 'next')->sole()->state)->toBe('completed');
    } finally {
        cleanupRedisPrefix($prefix);
    }
});

it('recovers terminal failure effects after Redis worker timeout SIGKILL', function () {
    $prefix = 'scarlett_test_'.bin2hex(random_bytes(5)).'_';
    $queue = 'scarlett-redis-kill-'.bin2hex(random_bytes(3));
    configureRedisQueue($prefix, $queue);

    try {
        $flow = app(Engine::class)->start(new Workflow([
            new Node(
                'timeout',
                SlowFailureAwareStep::class,
                queue: $queue,
                inputs: ['name' => 'timeout', 'mode' => 'slow-action'],
                execution: new ExecutionOptions(workerTimeout: 1, leaseSeconds: 5, attempts: 1),
            ),
        ]));

        $worker = new Process([PHP_BINARY, __DIR__.'/queue-redis-worker.php', $prefix, 'hook-timeout', $queue]);
        $worker->setTimeout(20);
        try {
            $worker->run();
        } catch (ProcessSignaledException) {
            expect($worker->getTermSignal())->toBe(SIGKILL);
        }

        expect($worker->getOutput())->toContain(TimeoutExceededException::class);

        $step = $flow->steps()->sole();
        expect((string) $step->state)->toBe('running');
        $step->update(['lease_until' => now()->subSecond()]);

        app(Engine::class)->resume($flow->id);

        $healthy = new Process([PHP_BINARY, __DIR__.'/queue-redis-worker.php', $prefix, 'normal', $queue]);
        $healthy->setTimeout(20);
        $healthy->mustRun();

        expect($flow->fresh()->status)->toBe('failed')
            ->and($flow->failures()->sole()->status)->toBe(Status::Completed)
            ->and($flow->fresh()->metadata['failures'][0]['cause'])->toBe('attempt_lost');
    } finally {
        cleanupRedisPrefix($prefix);
    }
});
