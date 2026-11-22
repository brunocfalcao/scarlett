<?php

declare(strict_types=1);

/**
 * Checks explicit archive and purge ages through their Artisan interfaces.
 *
 * Use old and new retained work alongside live controls, and small batches to force
 * multiple fetches. Repeating the command must not restart archive age or count rows
 * twice. Invalid and ambiguous options are rejected before any history changes; zero
 * is a supported explicit age rather than a missing option.
 */

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Scarlett\Definition\Node;
use Scarlett\Definition\Workflow;
use Scarlett\Engine;
use Scarlett\History;
use Scarlett\Models\StepRecord;
use Scarlett\Models\WorkflowRecord;
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

it('retries retention transactions after a transient concurrency error within the configured attempts', function (string $operation, bool $inject) {
    $engine = app(Engine::class);
    $history = app(History::class);
    $flow = $engine->start(new Workflow([new Node('old', EchoStep::class)]));
    if ($operation === 'purge') {
        $history->archive($flow->id);
    }
    $injected = false;
    if ($inject) {
        DB::beforeExecuting(function (string $sql) use (&$injected): void {
            if (! $injected && str_contains(str_replace('`', '"', $sql), 'from "scarlett_workflows" where "scarlett_workflows"."id" = ?')) {
                $injected = true;
                throw new RuntimeException('database is locked');
            }
        });
    }
    $result = match ($operation) {
        'archive' => $history->archive($flow->id),
        'archive before' => $history->archiveBefore(now()->addMinute(), 1),
        'purge' => $history->purge(now()->addDay(), 1),
        'delete' => $history->delete($flow->id),
    };
    expect($injected)->toBe($inject)
        ->and($result)->toBe($operation === 'archive before' || $operation === 'purge' ? 1 : true);
})->with(collect(['archive', 'archive before', 'purge', 'delete'])->crossJoin([true, false])->all());

it('reads only the retention columns it decides on and removes large step sets in bounded chunks', function () {
    $engine = app(Engine::class);
    $history = app(History::class);
    $done = $engine->start(new Workflow([new Node('old', EchoStep::class)]));
    Queue::fake();
    $statements = [];
    DB::listen(function (QueryExecuted $query) use (&$statements): void {
        $statements[] = ['sql' => str_replace('`', '"', $query->sql), 'bindings' => count($query->bindings)];
    });
    expect($history->archive($done->id))->toBeTrue();
    $stepReads = collect($statements)->pluck('sql')->filter(fn (string $sql): bool => str_contains($sql, 'from "scarlett_steps"'));
    expect($stepReads)->not->toBeEmpty()->each->toStartWith('select "id", "state" from "scarlett_steps"');
    $statements = [];
    expect($history->purge(now()->addDay(), 1))->toBe(1);
    $candidateReads = collect($statements)->pluck('sql')->filter(fn (string $sql): bool => str_contains($sql, 'from "scarlett_workflows" where "archived_at" <'));
    expect($candidateReads)->not->toBeEmpty()->each->toStartWith('select "id" from "scarlett_workflows"');
    $wide = $engine->start(new Workflow(array_map(fn (int $index): Node => new Node("step-{$index}", EchoStep::class), range(1, 501))));
    $statements = [];
    expect($history->delete($wide->id, includingRunning: true))->toBeTrue();
    foreach (['scarlett_history', 'scarlett_deliveries'] as $table) {
        $bindings = collect($statements)->filter(fn (array $statement): bool => str_starts_with($statement['sql'], "delete from \"{$table}\""))->pluck('bindings')->all();
        expect($bindings)->toBe([500, 1]);
    }
    expect(WorkflowRecord::whereKey($wide->id)->exists())->toBeFalse()
        ->and(StepRecord::where('workflow_id', $wide->id)->exists())->toBeFalse();
});
