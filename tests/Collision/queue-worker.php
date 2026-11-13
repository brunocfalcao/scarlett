<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Orchestra\Testbench\Foundation\Application;
use Scarlett\ScarlettServiceProvider;
use Scarlett\Tests\Collision\Environment;
use Scarlett\Tests\Fixtures\AmountMapper;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = Application::create(options: ['extra' => ['providers' => [ScarlettServiceProvider::class], 'dont-discover' => ['*']]]);
Environment::configure($app);
config(['queue.default' => 'database', 'queue.connections.database.connection' => 'mysql',
    'scarlett.payload_mappers' => ['amount-v1' => AmountMapper::class]]);
exit(Artisan::call('queue:work', ['connection' => 'database', '--queue' => 'scarlett', '--stop-when-empty' => true, '--sleep' => 0, '--tries' => 1]));
