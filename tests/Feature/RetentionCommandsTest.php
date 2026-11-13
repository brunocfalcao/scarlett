<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Queue;
use Scarlett\Definition\Node;
use Scarlett\Definition\Workflow;
use Scarlett\Engine;
use Scarlett\History;
use Scarlett\Tests\Fixtures\EchoStep;
use Scarlett\Tests\Fixtures\FailureAwareStep;

it('archives old settled workflows across batches without restarting retention or touching live work', function (string $unit, int $age) {
    $this->freezeSecond();
    $engine = app(Engine::class);
    $old = collect(range(1, 3))->map(fn ($index) => $engine->start(new Workflow([new Node('old-'.$index, EchoStep::class)])));
    Queue::fake();
    $active = $engine->start(new Workflow([controlled('active')]));
    $failure = $engine->start(new Workflow([new Node('failure', FailureAwareStep::class, inputs: ['name' => 'failure', 'mode' => 'fail'])]));
    runStep($failure->id, 'failure');
    $this->travel(1)->seconds();
    $boundary = $engine->start(new Workflow([controlled('boundary')]));
    runStep($boundary->id, 'boundary');
    $engine->cooldown();
    $held = $engine->start(new Workflow([controlled('held')]));
    $this->travel($age)->{$unit}();
    expect($old->every(fn ($flow) => $flow->fresh()->archived_at === null))->toBeTrue();
    $this->artisan('scarlett:archive', ['--'.$unit => (string) $age, '--batch' => '1'])->expectsOutput('Archived 3 workflow(s).')->assertSuccessful();
    $archivedAt = now()->toDateTimeString();
    foreach ($old as $flow) {
        expect($flow->fresh()->archived_at->toDateTimeString())->toBe($archivedAt);
    }
    foreach ([$active, $failure, $held, $boundary] as $flow) {
        expect($flow->fresh()->archived_at)->toBeNull();
    }
    $this->artisan('scarlett:archive', ['--'.$unit => (string) $age])->expectsOutput('Archived 0 workflow(s).')->assertSuccessful();
    $this->travel(1)->days();
    expect(app(History::class)->archive($old->first()->id))->toBeFalse();
    expect($old->first()->fresh()->archived_at->toDateTimeString())->toBe($archivedAt);
})->with([['minutes', 5], ['days', 1]]);

it('purges only old archived settled workflows across batches and safely repeats', function () {
    $this->freezeSecond();
    $engine = app(Engine::class);
    $history = app(History::class);
    $old = collect(range(1, 3))->map(function ($index) use ($engine, $history) {
        $flow = $engine->start(new Workflow([new Node('old-'.$index, EchoStep::class)]));
        $history->archive($flow->id);

        return $flow;
    });
    $unarchived = $engine->start(new Workflow([new Node('unarchived', EchoStep::class)]));
    Queue::fake();
    $active = $engine->start(new Workflow([controlled('active')]));
    $active->update(['archived_at' => now()]);
    $failure = $engine->start(new Workflow([new Node('failure', FailureAwareStep::class, inputs: ['name' => 'failure', 'mode' => 'fail'])]));
    runStep($failure->id, 'failure');
    $failure->update(['archived_at' => now()]);
    $this->travel(1)->seconds();
    $boundary = $engine->start(new Workflow([controlled('boundary')]));
    runStep($boundary->id, 'boundary');
    $history->archive($boundary->id);
    $this->travel(30)->days();
    expect($old->every(fn ($flow) => $flow->fresh()->status === 'completed'))->toBeTrue();
    $this->artisan('scarlett:purge', ['--days' => '30', '--batch' => '1'])->expectsOutput('Purged 3 workflow(s).')->assertSuccessful();
    foreach ($old as $flow) {
        expect($flow->fresh())->toBeNull();
    }
    expect($active->fresh()->status)->toBe('running')
        ->and($failure->failures()->unfinished()->count())->toBe(1)
        ->and($unarchived->fresh()->archived_at)->toBeNull()
        ->and($boundary->fresh()->status)->toBe('completed');
    $this->artisan('scarlett:purge', ['--days' => '30'])->expectsOutput('Purged 0 workflow(s).')->assertSuccessful();
});

it('accepts explicit zero ages and either unit for empty retention runs', function (string $command, array $options) {
    $this->artisan($command, $options)->assertSuccessful();
})->with(['scarlett:archive', 'scarlett:purge'])->with([[['--minutes' => '0']], [['--days' => '1']]]);

it('rejects invalid retention options before changing history', function (string $command, array $options) {
    $flow = app(Engine::class)->start(new Workflow([new Node('preserved', EchoStep::class)]));
    $flow->update(['finished_at' => now()->subYears(2), 'archived_at' => now()->subYear()]);
    $before = $flow->fresh()->getAttributes();
    $this->artisan($command, $options)->assertExitCode(2);
    expect($flow->fresh()->getAttributes())->toBe($before);
})->with(['scarlett:archive', 'scarlett:purge'])->with([
    'missing age' => [[]],
    'both ages' => [['--minutes' => '5', '--days' => '1']],
    'negative age' => [['--days' => '-1']],
    'fractional age' => [['--minutes' => '1.5']],
    'invalid age' => [['--days' => 'old']],
    'overflow age' => [['--days' => '999999999999999999999999']],
    'unrepresentable date' => [['--days' => (string) PHP_INT_MAX]],
    'zero batch' => [['--days' => '1', '--batch' => '0']],
    'invalid batch' => [['--days' => '1', '--batch' => 'many']],
]);
