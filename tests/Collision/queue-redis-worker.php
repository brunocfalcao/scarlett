<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;
use Orchestra\Testbench\Foundation\Application;
use Scarlett\ScarlettServiceProvider;
use Scarlett\Tests\Collision\Environment;
use Scarlett\Tests\Fixtures\AmountMapper;
use Scarlett\Tests\Fixtures\IntPriority;
use Scarlett\Tests\Fixtures\SourceRecord;
use Scarlett\Tests\Fixtures\StringStatus;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = Application::create(options: ['extra' => ['providers' => [ScarlettServiceProvider::class], 'dont-discover' => ['*']]]);
Environment::configure($app);

$prefix = $argv[1] ?? 'scarlett_redis_test_';
$mode = $argv[2] ?? 'normal';
$queueName = $argv[3] ?? 'scarlett';
if (isset($argv[4])) {
    Carbon::setTestNow($argv[4]);
}

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
        'queue' => $queueName,
        'retry_after' => 300,
        'block_for' => null,
        'after_commit' => false,
    ],
    'scarlett.queue' => $queueName,
    'scarlett.release_database_connection' => $mode === 'release',
    'scarlett.minimum_throttle_seconds' => 1,
    'scarlett.throttles' => [
        'burst' => ['max_requests' => 1, 'per_seconds' => 1],
    ],
    'scarlett.payload_mappers' => ['amount-v1' => AmountMapper::class],
    'scarlett.enums' => ['status' => StringStatus::class, 'priority' => IntPriority::class],
    'scarlett.models' => ['source_record' => SourceRecord::class],
]);

if ($mode === 'hook-timeout') {
    config(['queue.failed.driver' => 'null']);
    Queue::failing(static function ($event): void {
        echo $event->exception::class.PHP_EOL;
    });
    Queue::before(static fn () => app('queue.worker')->setCache(app('cache')->store('null')));
}

$code = Artisan::call('queue:work', [
    'connection' => 'redis',
    '--queue' => $queueName,
    '--stop-when-empty' => true,
    '--sleep' => 0,
    '--tries' => 1,
    '--max-jobs' => 0,
]);

echo json_encode([
    'exit' => $code,
    'peak_memory' => memory_get_peak_usage(true),
], JSON_THROW_ON_ERROR).PHP_EOL;

exit($code);
