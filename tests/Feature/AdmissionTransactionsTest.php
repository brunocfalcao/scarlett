<?php

declare(strict_types=1);

/**
 * Proves that application writes and starting follow-up workflows commit together.
 *
 * The consumer_records table stands in for application data. Queue fakes retain
 * publication assertions without executing jobs automatically. Cases inspect both
 * the business row and workflow records after commit, rollback, lost ownership, and
 * a missing admission gate; checking only the returned workflow would miss partial writes.
 */

use Illuminate\Database\RecordsNotFoundException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Scarlett\Definition\Node;
use Scarlett\Definition\Subject;
use Scarlett\Definition\SubjectSlot;
use Scarlett\Definition\Workflow;
use Scarlett\Engine;
use Scarlett\Execution\Context;
use Scarlett\Failures\Coordinator;
use Scarlett\Jobs\ExecuteStep;
use Scarlett\Models\WorkflowRecord;
use Scarlett\Tests\Fixtures\FailureAwareStep;

beforeEach(function () {
    Queue::fake();
    Schema::create('consumer_records', function (Blueprint $table): void {
        $table->uuid('id')->primary();
        $table->integer('writes')->default(0);
    });
});

it('commits consumer writes and admission together and rolls both back on an exception', function () {
    $engine = app(Engine::class);
    $subject = (string) Str::uuid();
    DB::table('consumer_records')->insert(['id' => $subject, 'writes' => 0]);
    $write = function () use ($engine, $subject) {
        DB::table('consumer_records')->where('id', $subject)->lockForUpdate()->sole();
        DB::table('consumer_records')->where('id', $subject)->increment('writes');
        $flow = $engine->start(new Workflow([controlled('admitted')], 'consumer', $subject));
        Queue::assertNothingPushed();

        return $flow;
    };
    $flow = $engine->withAdmission($write);
    expect($flow->subject_id)->toBe($subject)->and(DB::table('consumer_records')->where('id', $subject)->value('writes'))->toBe(1);
    Queue::assertPushed(ExecuteStep::class, 1);
    Queue::fake();
    expect(fn () => $engine->withAdmission(function () use ($write) {
        $write();
        throw new RuntimeException('Abort consumer change');
    }))->toThrow(RuntimeException::class, 'Abort consumer change');
    expect(DB::table('consumer_records')->where('id', $subject)->value('writes'))->toBe(1)
        ->and(WorkflowRecord::forSubject('consumer', $subject)->pluck('id')->all())->toBe([$flow->id]);
    Queue::assertNothingPushed();
});

it('admits guarded follow-ups from action and failure contexts and rejects obsolete ownership', function (bool $failureHook) {
    $engine = app(Engine::class);
    $subject = (string) Str::uuid();
    DB::table('consumer_records')->insert(['id' => $subject, 'writes' => 0]);
    $node = $failureHook ? new Node('action', FailureAwareStep::class, inputs: ['name' => 'action', 'mode' => 'fail']) : controlled('action');
    $flow = $engine->start(new Workflow([$node], 'consumer', $subject));
    $step = $flow->steps()->sole();
    if ($failureHook) {
        runStep($flow->id, 'action');
        $failure = $flow->failures()->sole();
        app(Coordinator::class)->claim($failure->id, $failure->attempt);
    } else {
        $engine->claim($step->id, $step->attempt);
    }
    $context = new Context($flow->id, $step->id, $failureHook ? $failure->attempt : $step->attempt, 0, failureId: $failureHook ? $failure->id : null);
    Queue::fake();
    $write = function () use ($engine, $subject): void {
        DB::table('consumer_records')->where('id', $subject)->lockForUpdate()->sole();
        DB::table('consumer_records')->where('id', $subject)->increment('writes');
        $engine->start(new Workflow([controlled('follow-up')], 'consumer', $subject));
        Queue::assertNothingPushed();
    };
    expect($engine->withAdmission(fn () => $context->whileCurrent($write)))->toBeTrue();
    Queue::assertPushed(ExecuteStep::class, 1);
    $engine->cancelWorkflow($flow->id);
    Queue::fake();
    expect($engine->withAdmission(fn () => $context->whileCurrent($write)))->toBeFalse()
        ->and(DB::table('consumer_records')->where('id', $subject)->value('writes'))->toBe(1)
        ->and(WorkflowRecord::forSubject('consumer', $subject)->count())->toBe(2);
    Queue::assertNothingPushed();
})->with([false, true]);

it('respects cooldown and an enclosing transaction rollback', function (bool $held) {
    $engine = app(Engine::class);
    if ($held) {
        $engine->cooldown();
    }
    $subject = (string) Str::uuid();
    DB::beginTransaction();
    try {
        $flow = $engine->withAdmission(fn () => $engine->start(new Workflow([controlled('held')], 'consumer', $subject)));
        expect($flow->status)->toBe($held ? 'held' : 'running')->and(WorkflowRecord::forSubject('consumer', $subject)->count())->toBe(1);
        Queue::assertNothingPushed();
    } finally {
        DB::rollBack();
    }
    expect(WorkflowRecord::forSubject('consumer', $subject)->count())->toBe(0);
    Queue::assertNothingPushed();
})->with([false, true]);

it('fails closed when the admission control row is missing', function (string $operation) {
    $engine = app(Engine::class);
    $subject = (string) Str::uuid();
    $flow = $engine->start(new Workflow([controlled('existing')], 'subject', $subject));
    $before = $flow->fresh()->getAttributes();
    $callbackCalled = false;
    $callback = function () use (&$callbackCalled): Workflow {
        $callbackCalled = true;

        return new Workflow([controlled('replacement')]);
    };
    Queue::fake();
    expect(DB::table('scarlett_control')->where('id', 'admission')->exists())->toBeTrue();
    DB::table('scarlett_control')->where('id', 'admission')->delete();

    expect(fn () => match ($operation) {
        'start' => $engine->start(new Workflow([controlled('missing-control')], 'subject', $subject)),
        'replace' => $engine->replace(new SubjectSlot('subject', $subject, 'current'), $callback),
        'withAdmission' => $engine->withAdmission($callback),
        'deleteSubject' => $engine->deleteSubject(new Subject('subject', $subject), $callback),
        'cooldown' => $engine->cooldown(),
        'reopen' => $engine->reopen(),
    })->toThrow(RecordsNotFoundException::class);
    expect($callbackCalled)->toBeFalse()
        ->and($flow->fresh()->getAttributes())->toBe($before)
        ->and(WorkflowRecord::forSubject('subject', $subject)->pluck('id')->all())->toBe([$flow->id]);
    Queue::assertNothingPushed();
})->with(['start', 'replace', 'withAdmission', 'deleteSubject', 'cooldown', 'reopen']);
