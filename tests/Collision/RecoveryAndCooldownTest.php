<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Scarlett\Definition\ExecutionOptions;
use Scarlett\Definition\Node;
use Scarlett\Definition\Workflow;
use Scarlett\Engine;
use Scarlett\Failures\Coordinator;
use Scarlett\Failures\Status;
use Scarlett\Models\WorkflowRecord;
use Scarlett\Tests\Collision\Workers;
use Scarlett\Tests\Fixtures\ControlledStep;
use Scarlett\Tests\Fixtures\FailureAwareStep;

it('renews one expired action attempt when two recovery commands race', function () {
    $engine = app(Engine::class);
    $flow = $engine->start(new Workflow([
        new Node('a', ControlledStep::class, inputs: ['name' => 'a', 'mode' => 'complete'], execution: new ExecutionOptions(attempts: 2, backoffSeconds: [0])),
    ]));

    $step = $flow->steps()->sole();
    $initialAttempt = $step->attempt;
    expect($engine->claim($step->id, $initialAttempt))->not->toBeNull();

    $step->update(['lease_until' => now()->subSecond()]);

    $operation = ['operation' => 'cli-recover', 'workflow' => $flow->id];
    $results = Workers::race($flow->id, [$operation, $operation]);

    // Both commands may observe unsent work before after-commit publication; attempt ownership is the invariant.
    $fresh = $step->fresh();

    expect(array_column($results, 'result'))->toBeIn([[1, 0], [0, 1], [1, 1]])
        ->and((string) $fresh->state)->toBe('queued')
        ->and($fresh->attempt)->not->toBe($initialAttempt)
        ->and($fresh->failures)->toBe(1)
        ->and(DB::table('scarlett_deliveries')->where('step_id', $step->id)->where('attempt', $fresh->attempt)->count())->toBe(1);
});

it('preserves one delivery intent when two recovery commands race on a stale queued delivery', function () {
    $engine = app(Engine::class);
    $flow = $engine->start(new Workflow([controlled('a')]));
    $step = $flow->steps()->sole();

    DB::table('scarlett_deliveries')
        ->where('step_id', $step->id)
        ->where('attempt', $step->attempt)
        ->update([
            'sent_at' => now()->subMinutes(5),
            'publishing_until' => null,
        ]);

    $operation = ['operation' => 'cli-recover', 'workflow' => $flow->id];
    $results = Workers::race($flow->id, [$operation, $operation]);

    $delivery = DB::table('scarlett_deliveries')
        ->where('step_id', $step->id)
        ->where('attempt', $step->attempt)
        ->sole();

    expect(array_column($results, 'result'))->toBeIn([[1, 0], [0, 1], [1, 1]])
        ->and($delivery->sent_at)->not->toBeNull()
        ->and(DB::table('scarlett_deliveries')->where('step_id', $step->id)->count())->toBe(1);
});

it('serializes reopen against concurrent admission during cooldown without leaving partial held workflows', function () {
    $engine = app(Engine::class);
    $engine->cooldown();

    $held = $engine->start(new Workflow([controlled('held')]));
    expect($held->status)->toBe('held');

    $results = Workers::race('', [['operation' => 'admit'], ['operation' => 'reopen']], 'scarlett_control');

    $admitted = WorkflowRecord::findOrFail($results[0]['result']);
    $heldFresh = $held->fresh();

    expect($results[1]['result'])->toBeTrue()
        ->and($heldFresh->status)->toBe('running')
        ->and($heldFresh->started_at)->not->toBeNull()
        ->and((string) $heldFresh->steps()->sole()->state)->toBe('queued')
        ->and($admitted->status)->toBeIn(['held', 'running'])
        ->and((string) $admitted->steps()->sole()->state)->toBe($admitted->status === 'held' ? 'pending' : 'queued')
        ->and($admitted->started_at === null)->toBe($admitted->status === 'held');
});

it('preserves one failure handler attempt when two recovery commands race', function (bool $running) {
    $flow = app(Engine::class)->start(new Workflow([
        new Node('action', FailureAwareStep::class, inputs: ['name' => 'action', 'mode' => 'fail']),
    ]));
    runStep($flow->id, 'action');
    $failure = $flow->failures()->sole();
    $attempt = $failure->attempt;
    $snapshot = $failure->snapshot;
    if ($running) {
        expect(app(Coordinator::class)->claim($failure->id, $attempt))->not->toBeNull();
        $failure->update(['lease_until' => now()->subSecond()]);
    } else {
        $failure->update(['sent_at' => now()->subMinutes(5), 'publishing_until' => null]);
    }
    expect($failure->fresh()->status)->toBe($running ? Status::Running : Status::Queued);
    $untouched = app(Engine::class)->start(new Workflow([controlled('untouched')]));
    $before = $untouched->steps()->sole()->getRawOriginal();

    $operation = ['operation' => 'cli-recover', 'workflow' => $flow->id];
    $results = Workers::race($flow->id, [$operation, $operation]);
    $fresh = $failure->fresh();

    expect(array_column($results, 'result'))->toBeIn([[1, 0], [0, 1], [1, 1]])
        ->and($fresh->status)->toBe(Status::Queued)
        ->and($fresh->failures)->toBe($running ? 1 : 0)
        ->and($fresh->attempt === $attempt)->toBe(! $running)
        ->and($fresh->snapshot)->toBe($snapshot)
        ->and($fresh->sent_at)->not->toBeNull()
        ->and($flow->failures()->count())->toBe(1)
        ->and($untouched->steps()->sole()->getRawOriginal())->toBe($before)
        ->and(app(Engine::class)->resume($flow->id))->toBe(0);

    $claim = ['operation' => 'failure-claim', 'failure' => $fresh->id, 'attempt' => $fresh->attempt];
    $claims = Workers::race($flow->id, [$claim, $claim]);
    expect(array_sum(array_column($claims, 'result')))->toBe(1)
        ->and($fresh->fresh()->status)->toBe(Status::Running);
})->with(['expired handler' => true, 'lost handler delivery' => false]);
