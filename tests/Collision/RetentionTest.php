<?php

declare(strict_types=1);

/**
 * Checks retention decisions while other transactions change settlement state.
 *
 * A candidate selected earlier is not permission to remove it now: reread under the
 * workflow lock, including unfinished failure hooks. Overlapping retention commands
 * must count each eligible workflow once. Snapshot cases establish a separate committed
 * change so an old consumer read cannot falsely prove the workflow is settled.
 */

use Illuminate\Support\Facades\DB;
use Scarlett\Definition\Node;
use Scarlett\Definition\Workflow;
use Scarlett\Engine;
use Scarlett\History;
use Scarlett\Tests\Collision\Environment;
use Scarlett\Tests\Collision\Workers;
use Scarlett\Tests\Fixtures\FailureAwareStep;

it('protects newly committed failure handlers inside an older transaction snapshot', function (string $operation) {
    $engine = app(Engine::class);
    $flow = $engine->start(new Workflow([new Node('failure', FailureAwareStep::class, inputs: ['name' => 'failure', 'mode' => 'fail'])]));
    if ($operation === 'purge') {
        $flow->update(['archived_at' => now()->subDays(31)]);
    }
    config(['database.connections.concurrent' => config('database.connections.'.Environment::driver())]);
    DB::beginTransaction();
    try {
        expect($flow->failures()->count())->toBe(0);
        Environment::concurrentCommit(fn () => runStep($flow->id, 'failure'));
        $history = app(History::class);
        expect($operation === 'archive' ? $history->archive($flow->id) : $history->purge(now()->subDays(30)))->toBe($operation === 'archive' ? false : 0);
        DB::commit();
        expect($flow->fresh()->status)->toBe('running')->and($flow->failures()->unfinished()->count())->toBe(1);
    } finally {
        DB::setDefaultConnection(Environment::driver());
        if (DB::transactionLevel() > 0) {
            DB::rollBack();
        }
        DB::purge('concurrent');
    }
})->with(['archive', 'purge']);

it('serializes overlapping retention commands and counts each eligible workflow once', function (string $operation, string $verb) {
    $flow = app(Engine::class)->start(new Workflow([controlled('done')]));
    runStep($flow->id, 'done');
    $flow->update(['finished_at' => now()->subDays(31)]);
    if ($operation === 'purge') {
        $flow->update(['archived_at' => now()->subDays(31)]);
    }
    $active = app(Engine::class)->start(new Workflow([controlled('active')]));
    $call = ['operation' => 'retention', 'command' => 'scarlett:'.$operation, 'options' => ['--days' => '30', '--batch' => '1']];
    $results = Workers::race($flow->id, [$call, $call]);
    expect(array_column(array_column($results, 'result'), 'exit'))->toBe([0, 0]);
    $outputs = array_column(array_column($results, 'result'), 'output');
    sort($outputs);
    expect($outputs)->toBe([$verb.' 0 workflow(s).', $verb.' 1 workflow(s).']);
    if ($operation === 'purge') {
        expect($flow->fresh())->toBeNull();
    } else {
        expect($flow->fresh()->archived_at->isToday())->toBeTrue();
    }
    expect($active->fresh()->status)->toBe('running')->and($active->fresh()->archived_at)->toBeNull();
})->with([['archive', 'Archived'], ['purge', 'Purged']]);
