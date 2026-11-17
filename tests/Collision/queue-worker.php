<?php

declare(strict_types=1);

/**
 * Standalone database-queue consumer used to test real transport boundaries.
 *
 * Boot a fresh Testbench application with the same isolated database and payload
 * registries as its parent. Fault modes inject lookup errors or worker timeout
 * conditions; failure output identifies the transport fault without depending on
 * an application cache counter. The worker stops when its queue has no ready work.
 */

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;
use Orchestra\Testbench\Foundation\Application;
use Scarlett\Models\StepRecord;
use Scarlett\ScarlettServiceProvider;
use Scarlett\Tests\Collision\Environment;
use Scarlett\Tests\Fixtures\AmountMapper;
use Scarlett\Tests\Fixtures\IntPriority;
use Scarlett\Tests\Fixtures\SourceRecord;
use Scarlett\Tests\Fixtures\StringStatus;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = Application::create(options: ['extra' => ['providers' => [ScarlettServiceProvider::class], 'dont-discover' => ['*']]]);
Environment::configure($app);
config(['queue.default' => 'database', 'queue.connections.database.connection' => Environment::driver(),
    'scarlett.payload_mappers' => ['amount-v1' => AmountMapper::class],
    'scarlett.enums' => ['status' => StringStatus::class, 'priority' => IntPriority::class],
    'scarlett.models' => ['source_record' => SourceRecord::class]]);
$mode = $argv[1] ?? null;
$fault = $mode !== null;
/**
 * Fault cases must expose the worker exception even without a failure-log store
 * or persistent cache. Limit selected modes to one job so a failed transport cannot
 * silently run later deliveries and mask the interrupted engine state.
 */
if ($fault) {
    config(['queue.failed.driver' => 'null']);
    Queue::failing(static function ($event): void {
        echo $event->exception::class.PHP_EOL;
    });
    Queue::before(static fn () => app('queue.worker')->setCache(app('cache')->store('null')));
}
if ($mode === 'lookup-failure') {
    StepRecord::retrieved(static fn () => throw new RuntimeException('Injected worker lookup failure.'));
}
exit(Artisan::call('queue:work', ['connection' => 'database', '--queue' => 'scarlett', '--stop-when-empty' => true, '--sleep' => 0, '--tries' => 1, '--max-jobs' => $fault && $mode !== 'hook-timeout' ? 1 : 0]));
