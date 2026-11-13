<?php

declare(strict_types=1);

use Orchestra\Testbench\Foundation\Application;
use Scarlett\Definition\Node;
use Scarlett\Definition\Workflow;
use Scarlett\Engine;
use Scarlett\Execution\Decision;
use Scarlett\ScarlettServiceProvider;
use Scarlett\Tests\Collision\Environment;
use Scarlett\Tests\Fixtures\EchoStep;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = Application::create(options: ['extra' => ['providers' => [ScarlettServiceProvider::class], 'dont-discover' => ['*']]]);
Environment::configure($app);
$arguments = json_decode($argv[1], true, flags: JSON_THROW_ON_ERROR);
$ready = $arguments['directory'].'/'.$arguments['worker'].'.ready';
file_put_contents($ready, 'ready');
$deadline = microtime(true) + 15;
while (! file_exists($arguments['directory'].'/release')) {
    if (microtime(true) > $deadline) {
        throw new RuntimeException('Collision barrier timed out.');
    }
    usleep(1000);
}
$started = microtime(true);
$engine = app(Engine::class);
$result = match ($arguments['operation']) {
    'admit' => $engine->start(new Workflow([new Node('admitted', EchoStep::class)]))->id,
    'cooldown' => (function () use ($engine): bool {
        $engine->cooldown();

        return true;
    })(),
    'claim' => $engine->claim($arguments['step'], $arguments['attempt']) !== null,
    'cancel' => $engine->cancel($arguments['step']),
    'recover' => $engine->recover($arguments['step']),
    'finish' => $engine->finish($arguments['step'], $arguments['attempt']),
    'old-outcome' => $engine->decide($arguments['step'], $arguments['attempt'], Decision::fail()),
};
echo json_encode(['result' => $result, 'started' => $started, 'ended' => microtime(true)], JSON_THROW_ON_ERROR).PHP_EOL;
