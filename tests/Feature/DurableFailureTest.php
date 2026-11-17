<?php

declare(strict_types=1);

/**
 * Checks the separate durable lifecycle that follows terminal business failure.
 *
 * Action failure, failure-hook execution, and failure-hook retries have different
 * records and counters. Cases inspect original causes, stable failure IDs, fallback
 * metadata, and guarded effects. Child hooks must finish before parent hooks; cancelled
 * or successful work must not accidentally register terminal failure effects.
 */

use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Scarlett\Definition\ExecutionOptions;
use Scarlett\Definition\Node;
use Scarlett\Definition\SubjectSlot;
use Scarlett\Definition\Workflow;
use Scarlett\Engine;
use Scarlett\Execution\Context;
use Scarlett\Execution\Decision;
use Scarlett\Failures\Coordinator;
use Scarlett\Failures\Runner as FailureRunner;
use Scarlett\Failures\Status;
use Scarlett\History;
use Scarlett\Inputs\ModelReference;
use Scarlett\Inputs\Service;
use Scarlett\Jobs\ExecuteFailure;
use Scarlett\Models\FailureRecord;
use Scarlett\Models\WorkflowRecord;
use Scarlett\States\Running;
use Scarlett\Tests\Fixtures\Approved;
use Scarlett\Tests\Fixtures\FailureAwareStep;
use Scarlett\Tests\Fixtures\GuardedApproval;
use Scarlett\Tests\Fixtures\InstanceFailureStep;
use Scarlett\Tests\Fixtures\SourceRecord;

beforeEach(function () {
    Queue::fake();
    $this->freezeSecond();
    FailureAwareStep::$hookCalls = 0;
    FailureAwareStep::$hookThrows = false;
    FailureAwareStep::$constructionThrows = false;
});

it('defaults terminal hook transport timeout while preserving the named timeout argument', function () {
    config(['scarlett.worker_timeout' => 37]);

    expect((new ExecuteFailure('failure', 'attempt'))->timeout)->toBe(37)
        ->and((new ExecuteFailure('failure', 'attempt', timeout: 11))->timeout)->toBe(11);
});

it('extends only a current failure lease and rejects stale expired and cancelled heartbeats', function () {
    $this->freezeSecond();
    $flow = failureFlow('fail');
    runStep($flow->id, 'action');
    $failure = $flow->failures()->sole();
    $coordinator = app(Coordinator::class);
    expect($coordinator->claim($failure->id, $failure->attempt)?->id)->toBe($failure->id);
    $originalLease = $failure->fresh()->lease_until;
    $this->travel(10)->seconds();
    expect($coordinator->heartbeat($failure->id, $failure->attempt))->toBeTrue()
        ->and($failure->fresh()->lease_until->equalTo($originalLease->addSeconds(10)))->toBeTrue();
    $before = $failure->fresh()->getAttributes();
    expect($coordinator->heartbeat($failure->id, 'stale-attempt'))->toBeFalse()
        ->and($failure->fresh()->getAttributes())->toBe($before);
    $this->travel(300)->seconds();
    expect($coordinator->heartbeat($failure->id, $failure->attempt))->toBeFalse()
        ->and($failure->fresh()->getAttributes())->toBe($before);
    app(Engine::class)->cancelWorkflow($flow->id);
    $cancelled = $failure->fresh()->getAttributes();
    expect($coordinator->heartbeat($failure->id, $failure->attempt))->toBeFalse()
        ->and($failure->fresh()->status)->toBe(Status::Cancelled)
        ->and($failure->fresh()->getAttributes())->toBe($cancelled)
        ->and($coordinator->heartbeat((string) Str::uuid(), 'missing-attempt'))->toBeFalse();
});

function failureFlow(string $mode, int $attempts = 1): WorkflowRecord
{
    return app(Engine::class)->start(new Workflow([new Node('action', FailureAwareStep::class, inputs: ['name' => 'action', 'mode' => $mode], execution: new ExecutionOptions(attempts: $attempts, backoffSeconds: [3], verificationAttempts: 1))]));
}
function handleFailure(string $step): FailureRecord
{
    $failure = FailureRecord::where('step_id', $step)->sole();
    app(FailureRunner::class)->run($failure->id, $failure->attempt);

    return $failure->fresh();
}

it('durably handles terminal origins with a stable cause and useful reason exactly once per claimed attempt', function (string $mode, string $cause, string $reason) {
    $flow = failureFlow($mode);
    $step = $flow->steps()->sole();
    expect(FailureRecord::where('step_id', $step->id)->exists())->toBeFalse();
    if ($mode === 'lost') {
        app(Engine::class)->claim($step->id, $step->attempt);
        $this->travel(300)->seconds();
        app(Engine::class)->resume($flow->id);
    } else {
        runStep($flow->id, 'action');
    }
    expect($step->fresh()->error)->toBe($reason)->and($flow->fresh()->finished_at)->toBeNull();
    $failure = handleFailure($step->id);
    app(FailureRunner::class)->run($failure->id, $failure->attempt);
    // JSON object member order is not preserved by every database; types and values are.
    $record = collect($flow->fresh()->metadata['failures'])->sole();
    ksort($record);
    expect($failure->status)->toBe(Status::Completed)->and(FailureAwareStep::$hookCalls)->toBe(1)
        ->and($record)->toBe(['cause' => $cause, 'fallback' => false, 'id' => $failure->id, 'name' => 'action', 'reason' => $reason])
        ->and($flow->fresh()->status)->toBe('failed');
})->with([
    ['fail', 'decided', 'A useful failure reason.'], ['stop', 'decided', 'A useful stop reason.'],
    ['classified', 'decided', 'Readable explanation.'], ['throw', 'budget_exhausted', 'test failure'],
    ['lost', 'attempt_lost', 'Execution lease expired.'], ['verify', 'budget_exhausted', 'Completion verification did not pass.'], ['handler-crash', 'handler_crashed', 'Handler crashed.'],
]);

it('runs failure handling from stored identifiers when original construction cannot succeed', function () {
    $flow = failureFlow('success');
    $step = $flow->steps()->sole();
    FailureAwareStep::$constructionThrows = true;
    runStep($flow->id, 'action');
    $failure = handleFailure($step->id);
    expect($failure->status)->toBe(Status::Completed)->and($flow->fresh()->metadata['failures'][0]['fallback'])->toBeTrue()
        ->and($flow->fresh()->metadata['failures'][0]['name'])->toBe('action');
});

it('recovers a lost hook attempt and rejects its stale guarded writes', function () {
    $flow = failureFlow('fail', 2);
    runStep($flow->id, 'action');
    $step = $flow->steps()->sole();
    $failure = FailureRecord::where('step_id', $step->id)->sole();
    app(Coordinator::class)->claim($failure->id, $failure->attempt);
    $context = new Context($flow->id, $step->id, $failure->attempt, 0, failureId: $failure->id);
    expect($context->isCurrent())->toBeTrue();
    $this->travel(300)->seconds();
    app(Engine::class)->resume($flow->id);
    expect($context->whileCurrent(fn () => throw new LogicException('stale write')))->toBeFalse();
    $this->travel(3)->seconds();
    $completed = handleFailure($step->id);
    expect($completed->status)->toBe(Status::Completed)->and($completed->failures)->toBe(1)
        ->and($flow->fresh()->metadata['failures'])->toHaveCount(1);
});

it('runs child failure hooks before their parent and preserves rescue behavior', function () {
    $flow = app(Engine::class)->start(new Workflow([
        new Node('parent', FailureAwareStep::class, inputs: ['name' => 'parent']),
        new Node('child', FailureAwareStep::class, parent: 'parent', inputs: ['name' => 'child', 'mode' => 'fail']),
        controlled('rescue', rescues: 'parent'),
    ]));
    runStep($flow->id, 'parent');
    runStep($flow->id, 'child');
    $parent = $flow->steps()->where('key', 'parent')->sole();
    $child = $flow->steps()->where('key', 'child')->sole();
    expect(FailureRecord::where('step_id', $parent->id)->sole()->status)->toBe(Status::Pending)
        ->and(statesOf($flow->id)['rescue'])->toBe('queued');
    handleFailure($child->id);
    handleFailure($parent->id);
    runStep($flow->id, 'rescue');
    expect(array_column($flow->fresh()->metadata['failures'], 'name'))->toBe(['child', 'parent'])
        ->and($flow->fresh()->metadata['failures'][1]['cause'])->toBe('child_failed')
        ->and($flow->fresh()->status)->toBe('failed');
});

it('retries hook errors within their own budget and protects unfinished failure history', function () {
    $flow = failureFlow('fail', 2);
    runStep($flow->id, 'action');
    $step = $flow->steps()->sole();
    expect(app(History::class)->delete($flow->id))->toBeFalse();
    FailureAwareStep::$hookThrows = true;
    expect(handleFailure($step->id)->status)->toBe(Status::Queued);
    $this->travel(3)->seconds();
    expect(handleFailure($step->id)->status)->toBe(Status::Failed)
        ->and(FailureAwareStep::$hookCalls)->toBe(2)
        ->and($step->fresh()->error)->toBe('A useful failure reason.');
    expect(app(History::class)->delete($flow->id))->toBeTrue();
    expect(FailureRecord::where('step_id', $step->id)->exists())->toBeFalse();
});

it('never invokes failure hooks for cancelled skipped or successfully completed work', function (string $mode) {
    $flow = failureFlow($mode);
    $step = $flow->steps()->sole();
    if ($mode === 'cancel') {
        app(Engine::class)->cancelWorkflow($flow->id);
    } else {
        runStep($flow->id, 'action');
    }
    expect(FailureRecord::where('step_id', $step->id)->exists())->toBeFalse()->and(FailureAwareStep::$hookCalls)->toBe(0);
})->with(['cancel', 'skip', 'success']);

it('uses persisted scalar identifiers when a service or model input is no longer available', function (string $input) {
    if ($input === 'model') {
        Schema::create('source_records', function ($table) {
            $table->id();
            $table->string('name');
        });
        config(['scarlett.models' => ['source' => SourceRecord::class]]);
        $value = SourceRecord::create(['name' => 'deleted-input']);
    } else {
        $value = new Service('missing.consumer.service');
    }
    $flow = app(Engine::class)->start(new Workflow([
        new Node('action', FailureAwareStep::class, inputs: ['name' => 'persisted-id', 'value' => $input === 'model' ? new ModelReference('source', $value->getKey()) : $value]),
    ], subjectType: 'test', subjectId: 'retained-id', metadata: ['retained' => true]));
    if ($input === 'model') {
        $value->delete();
    }
    runStep($flow->id, 'action');
    $failure = handleFailure($flow->steps()->sole()->id);
    expect($failure->status)->toBe(Status::Completed)->and($failure->cause->value)->toBe('input_resolution')
        ->and($failure->snapshot['subject_id'])->toBe('retained-id')
        ->and($flow->fresh()->metadata['failures'][0]['name'])->toBe('persisted-id')
        ->and($flow->fresh()->metadata['failures'][0]['fallback'])->toBeTrue();
})->with(['model', 'service']);

it('records a diagnostic instead of silently discarding an unavailable instance-only handler', function () {
    $flow = app(Engine::class)->start(new Workflow([
        new Node('action', InstanceFailureStep::class,
            inputs: ['name' => 'id', 'value' => new Service('missing.consumer.service')],
            execution: new ExecutionOptions(attempts: 1)),
    ]));
    runStep($flow->id, 'action');
    $failure = handleFailure($flow->steps()->sole()->id);
    expect($failure->status)->toBe(Status::Failed)->and($failure->error)->toContain('onFailureWithoutInstance');
});

it('still settles missing step classes and preserves a recoverable failure diagnostic', function () {
    $flow = failureFlow('success');
    $step = $flow->steps()->sole();
    $step->update(['class' => 'DeletedConsumerStep']);
    runStep($flow->id, 'action');
    expect((string) $step->fresh()->state)->toBe('failed');
    expect(handleFailure($step->id)->status)->toBe(Status::Failed);
});

it('holds the parent hook until an already-running sibling and its hook settle', function () {
    $flow = app(Engine::class)->start(new Workflow([
        new Node('parent', FailureAwareStep::class, inputs: ['name' => 'parent']),
        new Node('first', FailureAwareStep::class, parent: 'parent', inputs: ['name' => 'first', 'mode' => 'fail']),
        new Node('later', FailureAwareStep::class, parent: 'parent', inputs: ['name' => 'later']),
    ]));
    runStep($flow->id, 'parent');
    $later = $flow->steps()->where('key', 'later')->sole();
    app(Engine::class)->claim($later->id, $later->attempt);
    runStep($flow->id, 'first');
    handleFailure($flow->steps()->where('key', 'first')->sole()->id);
    $parent = $flow->steps()->where('key', 'parent')->sole();
    expect((string) $parent->state)->toBe('waitingforchildren')
        ->and(FailureRecord::where('step_id', $parent->id)->exists())->toBeFalse();
    app(Engine::class)->decide($later->id, $later->attempt, Decision::stop('Later child stopped.'));
    expect(FailureRecord::where('step_id', $parent->id)->sole()->status)->toBe(Status::Pending);
    handleFailure($later->id);
    handleFailure($parent->id);
    expect(array_column($flow->fresh()->metadata['failures'], 'name'))->toBe(['first', 'later', 'parent']);
});

it('restores hook ownership after a replacement veto and revokes it after replacement commits', function () {
    $slot = new SubjectSlot('test', 'id', 'current');
    $engine = app(Engine::class);
    $flow = $engine->replace($slot, fn () => new Workflow([
        new Node('action', FailureAwareStep::class, inputs: ['name' => 'action', 'mode' => 'fail']),
    ]));
    runStep($flow->id, 'action');
    $failure = $flow->failures()->sole();
    app(Coordinator::class)->claim($failure->id, $failure->attempt);
    $context = new Context($flow->id, $failure->step_id, $failure->attempt, 0, failureId: $failure->id);
    expect($context->canRetry())->toBeTrue()->and($context->heartbeat())->toBeTrue();
    expect($engine->replace($slot, function () use ($context) {
        expect($context->whileCurrent(fn () => throw new LogicException('revoked')))->toBeFalse();

        return false;
    }))->toBeNull();
    expect($context->whileCurrent(fn () => null))->toBeTrue();
    $replacement = $engine->replace($slot, fn () => new Workflow([controlled('next')]));
    expect($context->whileCurrent(fn () => throw new LogicException('revoked')))->toBeFalse()
        ->and($failure->fresh()->status)->toBe(Status::Cancelled)
        ->and($engine->current($slot))->toBe($replacement->id);
});

it('recovers missing failure delivery without duplicating the durable record', function () {
    $flow = failureFlow('fail');
    runStep($flow->id, 'action');
    $failure = $flow->failures()->sole();
    expect($failure->status)->toBe(Status::Queued)->and(app(Engine::class)->stalled($flow->id))->toBeFalse();
    $failure->update(['sent_at' => null]);
    expect(app(Engine::class)->stalled($flow->id))->toBeTrue();
    expect(app(Engine::class)->resume($flow->id))->toBe(1);
    expect($flow->failures()->sole()->id)->toBe($failure->id);
    Queue::assertPushed(ExecuteFailure::class, 2);
    expect(handleFailure($failure->step_id)->status)->toBe(Status::Completed);
});

it('dispatches typed failure handling when result persistence or completion guards reject the outcome', function (string $mode) {
    GuardedApproval::$allowed = false;
    config(['scarlett.states' => [Approved::class], 'scarlett.transitions' => [
        [Running::class, Approved::class, GuardedApproval::class],
    ]]);
    app()->bind('unserializable.result', fn () => fn () => null);
    $flow = app(Engine::class)->start(new Workflow([
        new Node('action', FailureAwareStep::class, inputs: ['name' => 'action', 'mode' => $mode,
            'value' => new Service('unserializable.result')]),
    ]));
    runStep($flow->id, 'action');
    $failure = handleFailure($flow->steps()->sole()->id);
    expect($failure->status)->toBe(Status::Completed)
        ->and($failure->cause->value)->toBe($mode === 'approved' ? 'completion_rejected' : 'result_persistence')
        ->and($flow->fresh()->metadata['failures'][0]['reason'])->toBe($flow->steps()->sole()->error);
})->with(['approved', 'success']);
