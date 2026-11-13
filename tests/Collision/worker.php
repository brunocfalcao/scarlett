<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Orchestra\Testbench\Foundation\Application;
use Scarlett\Definition\Node;
use Scarlett\Definition\Subject;
use Scarlett\Definition\SubjectSlot;
use Scarlett\Definition\Workflow;
use Scarlett\Engine;
use Scarlett\Exceptions\DeletionVetoed;
use Scarlett\Execution\Decision;
use Scarlett\Failures\Coordinator;
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
    'delete-subject' => $engine->deleteSubject(new Subject('consumer', $arguments['subject']), function ($locked) use ($arguments) {
        if (($arguments['check_running'] ?? false) && $locked->contains(fn ($flow) => $flow->steps->contains(fn ($step) => (string) $step->state === 'running'))) {
            return false;
        }
        if (isset($arguments['record'])) {
            DB::table('consumer_records')->where('id', $arguments['record'])->lockForUpdate()->sole();
            if ($arguments['delete_record'] ?? false) {
                DB::table('consumer_records')->where('id', $arguments['record'])->delete();
            } else {
                DB::table('consumer_records')->where('id', $arguments['record'])->increment('writes');
            }
        }

        return ! ($arguments['veto'] ?? false);
    }, guardSubjects: array_map(fn ($id) => new Subject('consumer', $id), $arguments['guards'] ?? [])),
    'subject-admit' => (function () use ($engine, $arguments): ?string {
        try {
            return DB::transaction(function () use ($engine, $arguments): string {
                $workflow = $engine->start(new Workflow([new Node('admitted', EchoStep::class)], 'consumer', $arguments['subject']));
                if (! DB::table('consumer_records')->where('id', $arguments['record'])->lockForUpdate()->exists()) {
                    throw new DeletionVetoed;
                }

                return $workflow->id;
            });
        } catch (DeletionVetoed) {
            return null;
        }
    })(),
    'replace' => $engine->replace(new SubjectSlot('consumer', $arguments['subject'], 'current'), function () use ($arguments) {
        if (isset($arguments['record'])) {
            DB::table('consumer_records')->where('id', $arguments['record'])->lockForUpdate()->sole();
            DB::table('consumer_records')->where('id', $arguments['record'])->increment('writes');
        }

        return ($arguments['veto'] ?? false) ? null : new Workflow([new Node('replaced', EchoStep::class)]);
    })?->id,
    'failure-claim' => app(Coordinator::class)->claim($arguments['failure'], $arguments['attempt']) !== null,
    'failure-write' => app(Coordinator::class)->whileCurrent($arguments['failure'], $arguments['attempt'], fn () => DB::table('consumer_records')->where('id', $arguments['record'])->increment('writes')),
    'failure-recover' => app(Coordinator::class)->recover($arguments['failure']),
    'admit' => $engine->start(new Workflow([new Node('admitted', EchoStep::class)]))->id,
    'cooldown' => (function () use ($engine): bool {
        $engine->cooldown();

        return true;
    })(),
    'claim' => $engine->claim($arguments['step'], $arguments['attempt']) !== null,
    'revoke' => $engine->cancelWorkflow($arguments['workflow']),
    'write' => $engine->whileCurrent($arguments['step'], $arguments['attempt'], fn () => DB::table('consumer_records')->where('id', $arguments['record'])->increment('writes')),
    'cancel' => $engine->cancel($arguments['step']),
    'recover' => $engine->recover($arguments['step']),
    'finish' => $engine->finish($arguments['step'], $arguments['attempt']),
    'old-outcome' => $engine->decide($arguments['step'], $arguments['attempt'], Decision::fail()),
};
echo json_encode(['result' => $result, 'started' => $started, 'ended' => microtime(true)], JSON_THROW_ON_ERROR).PHP_EOL;
