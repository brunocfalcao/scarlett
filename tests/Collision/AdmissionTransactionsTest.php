<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Scarlett\Definition\Node;
use Scarlett\Definition\Workflow;
use Scarlett\Engine;
use Scarlett\Failures\Coordinator;
use Scarlett\Models\WorkflowRecord;
use Scarlett\Tests\Collision\Workers;
use Scarlett\Tests\Fixtures\FailureAwareStep;

beforeEach(function () {
    Schema::create('consumer_records', function (Blueprint $table): void {
        $table->uuid('id')->primary();
        $table->integer('writes')->default(0);
    });
});

it('orders atomic writes and follow-up admission before deletion without retrying a deadlock', function (string $kind, bool $deletionFirst) {
    $subject = (string) Str::uuid();
    DB::table('consumer_records')->insert(['id' => $subject, 'writes' => 0]);
    $node = $kind === 'failure'
        ? new Node('action', FailureAwareStep::class, inputs: ['name' => 'action', 'mode' => 'fail'])
        : controlled('action');
    $engine = app(Engine::class);
    $flow = $engine->start(new Workflow([$node], 'consumer', $subject));
    $step = $flow->steps()->sole();
    if ($kind === 'failure') {
        runStep($flow->id, 'action');
        $failure = $flow->failures()->sole();
        app(Coordinator::class)->claim($failure->id, $failure->attempt);
    } else {
        $engine->claim($step->id, $step->attempt);
    }
    $unrelated = $engine->start(new Workflow([controlled('unrelated')], 'consumer', (string) Str::uuid()));
    $write = [
        'operation' => 'write-and-admit', 'kind' => $kind, 'subject' => $subject,
        'step' => $step->id, 'attempt' => $kind === 'failure' ? $failure->attempt : $step->attempt,
        'failure' => $kind === 'failure' ? $failure->id : null,
        'wait_for_admission_waiter' => $deletionFirst,
        'transaction_attempts' => 1,
    ];
    $delete = [
        'operation' => 'delete-subject', 'subject' => $subject, 'record' => $subject,
        'delete_record' => true, 'wait_for_admission_waiter' => ! $deletionFirst,
        'transaction_attempts' => 1,
    ];
    $results = Workers::race($flow->id, [$write, $delete], 'scarlett_control');
    expect($results[0]['result'])->toBe(! $deletionFirst)->and($results[1]['result'])->toBeTrue()
        ->and(WorkflowRecord::forSubject('consumer', $subject)->count())->toBe(0)
        ->and(DB::table('consumer_records')->where('id', $subject)->exists())->toBeFalse()
        ->and($unrelated->fresh()->status)->toBe('running');
})->with(['step', 'consumer', 'failure'])->with([true, false]);

it('rejects cancelled action or failure ownership inside an older outer transaction snapshot', function (bool $failureHook) {
    $engine = app(Engine::class);
    $node = $failureHook ? new Node('action', FailureAwareStep::class, inputs: ['name' => 'action', 'mode' => 'fail']) : controlled('action');
    $flow = $engine->start(new Workflow([$node]));
    $step = $flow->steps()->sole();
    if ($failureHook) {
        runStep($flow->id, 'action');
        $failure = $flow->failures()->sole();
        app(Coordinator::class)->claim($failure->id, $failure->attempt);
    } else {
        $engine->claim($step->id, $step->attempt);
    }
    config(['database.connections.concurrent' => config('database.connections.mysql')]);
    DB::beginTransaction();
    try {
        expect((string) $step->fresh()->state)->toBe($failureHook ? 'failed' : 'running');
        DB::setDefaultConnection('concurrent');
        $engine->cancelWorkflow($flow->id);
        DB::setDefaultConnection('mysql');
        $written = false;
        $write = function () use (&$written): void {
            $written = true;
        };
        $accepted = $failureHook
            ? app(Coordinator::class)->whileCurrent($failure->id, $failure->attempt, $write)
            : $engine->whileCurrent($step->id, $step->attempt, $write);
        expect($accepted)->toBeFalse()->and($written)->toBeFalse();
    } finally {
        DB::setDefaultConnection('mysql');
        DB::rollBack();
        DB::purge('concurrent');
    }
})->with([false, true]);
