<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Queue;
use Scarlett\Definition\ExecutionOptions;
use Scarlett\Definition\Node;
use Scarlett\Definition\SubjectSlot;
use Scarlett\Definition\Workflow;
use Scarlett\Engine;
use Scarlett\Execution\Runner;
use Scarlett\Inputs\FromResult;
use Scarlett\Inputs\PayloadCodec;
use Scarlett\Jobs\ExecuteStep;
use Scarlett\Models\WorkflowRecord;
use Scarlett\States\Completed;
use Scarlett\Tests\Fixtures\ControlledStep;
use Scarlett\Tests\Fixtures\DispatcherParityStep;
use Scarlett\Tests\Fixtures\EchoStep;
use Scarlett\Tests\Fixtures\FailureAwareStep;

beforeEach(function () {
    Queue::fake();
    $this->freezeSecond();
    ControlledStep::$actions = [];
    ControlledStep::$constructed = [];
    DispatcherParityStep::$events = [];
    DispatcherParityStep::$hookCalls = 0;
    FailureAwareStep::$hookCalls = 0;
});

it('persists falsy scalar, empty array, zero float and null results and passes them downstream via FromResult', function (mixed $expected) {
    $flow = app(Engine::class)->start(new Workflow([
        new Node('producer', DispatcherParityStep::class, inputs: ['name' => 'producer', 'mode' => 'value', 'value' => $expected]),
        new Node('consumer', EchoStep::class, stage: 1, inputs: ['value' => new FromResult('producer')]),
    ]));

    runStep($flow->id, 'producer');
    runStep($flow->id, 'consumer');

    $producer = $flow->steps()->where('key', 'producer')->sole();
    $consumer = $flow->steps()->where('key', 'consumer')->sole();
    $codec = app(PayloadCodec::class);

    expect($flow->fresh()->status)->toBe('completed')
        ->and($producer->has_result)->toBeTrue()
        ->and($codec->decode($producer->result))->toBe($expected)
        ->and($consumer->has_result)->toBeTrue()
        ->and($codec->decode($consumer->result))->toBe($expected);
})->with([
    'integer zero' => [0],
    'boolean false' => [false],
    'empty string' => [''],
    'empty array' => [[]],
    'float zero' => [0.0],
    'null' => [null],
]);

it('completes a parent when one parallel child skips while another completes and keeps rescues skipped', function () {
    $flow = app(Engine::class)->start(new Workflow([
        controlled('parent'),
        controlled('done', parent: 'parent'),
        controlled('skipped', 'skip', parent: 'parent'),
        controlled('rescue', rescues: 'parent'),
        controlled('after', stage: 1),
    ]));

    runStep($flow->id, 'parent');
    runStep($flow->id, 'done');
    runStep($flow->id, 'skipped');
    runStep($flow->id, 'parent');
    runStep($flow->id, 'after');

    expect($flow->fresh()->status)->toBe('completed')
        ->and(statesOf($flow->id))->toBe([
            'after' => 'completed',
            'done' => 'completed',
            'parent' => 'completed',
            'rescue' => 'skipped',
            'skipped' => 'skipped',
        ]);
});

it('refuses non-forced cancellation of a WaitingForChildren parent and isolates stage failures across parallel work and workflows', function () {
    $engine = app(Engine::class);

    $parentFlow = $engine->start(new Workflow([
        controlled('parent'),
        controlled('child', parent: 'parent'),
    ], subjectType: 'order', subjectId: '101'));

    runStep($parentFlow->id, 'parent');
    $child = $parentFlow->steps()->where('key', 'child')->sole();
    $engine->claim($child->id, $child->attempt);
    $parent = $parentFlow->steps()->where('key', 'parent')->sole();

    expect($engine->cancel($parent->id, includingRunning: false))->toBeFalse()
        ->and((string) $parent->fresh()->state)->toBe('waitingforchildren');

    $isolatedA = $engine->start(new Workflow([
        controlled('fail-left', 'fail'),
        controlled('fail-right', 'fail'),
        controlled('running-sibling'),
        controlled('stage-1', stage: 1),
    ], subjectType: 'order', subjectId: '101'));

    $unrelatedB = $engine->start(new Workflow([
        controlled('independent'),
    ], subjectType: 'order', subjectId: '101'));

    $runningSibling = $isolatedA->steps()->where('key', 'running-sibling')->sole();
    $engine->claim($runningSibling->id, $runningSibling->attempt);

    runStep($isolatedA->id, 'fail-left');
    runStep($isolatedA->id, 'fail-right');

    expect((string) $runningSibling->fresh()->state)->toBe('running')
        ->and((string) $isolatedA->steps()->where('key', 'stage-1')->sole()->state)->toBe('cancelled')
        ->and($isolatedA->fresh()->status)->toBe('running');

    $engine->saveAction($runningSibling->id, $runningSibling->attempt, 'done', Completed::class);
    $engine->finish($runningSibling->id, $runningSibling->attempt);
    runStep($unrelatedB->id, 'independent');

    expect($isolatedA->fresh()->status)->toBe('failed')
        ->and((string) $runningSibling->fresh()->state)->toBe('completed')
        ->and($unrelatedB->fresh()->status)->toBe('completed');
});

it('orders deliveries by priority within a stage and inherits queue, connection, and priority onto runtime children', function () {
    $flow = app(Engine::class)->start(new Workflow([
        new Node('low', EchoStep::class, priority: 1, queue: 'low-q', inputs: ['value' => 'low']),
        new Node('high', DispatcherParityStep::class, priority: 10, queue: 'priority-q', connection: 'sync', inputs: [
            'name' => 'high',
            'mode' => 'spawn',
            'value' => 'deep',
            'spawnDepth' => 2,
        ]),
    ]));

    $pushedOrder = [];
    foreach (Queue::pushed(ExecuteStep::class) as $job) {
        $pushedOrder[] = [$job->stepId, $job->queue, $job->connection];
    }

    $highStep = $flow->steps()->where('key', 'high')->sole();
    $lowStep = $flow->steps()->where('key', 'low')->sole();

    expect($pushedOrder)->toBe([
        [$highStep->id, 'priority-q', 'sync'],
        [$lowStep->id, 'low-q', null],
    ]);

    app(Runner::class)->run($highStep->id, $highStep->attempt);
    $runtimeChild = $flow->steps()->where('key', $highStep->id.'/child-2')->sole();

    expect($runtimeChild->queue)->toBe('priority-q')
        ->and($runtimeChild->connection)->toBe('sync')
        ->and($runtimeChild->priority)->toBe(10);

    app(Runner::class)->run($runtimeChild->id, $runtimeChild->attempt);
    $runtimeGrandchild = $flow->steps()->where('key', $runtimeChild->id.'/child-1')->sole();

    expect($runtimeGrandchild->queue)->toBe('priority-q')
        ->and($runtimeGrandchild->connection)->toBe('sync')
        ->and($runtimeGrandchild->priority)->toBe(10);
});

it('allows already-running multi-stage and runtime-child workflows to finish during cooldown while new work stays held', function () {
    $engine = app(Engine::class);

    $running = $engine->start(new Workflow([
        new Node('stage-0', DispatcherParityStep::class, inputs: [
            'name' => 'stage-0',
            'mode' => 'spawn',
            'value' => 'expanded',
            'spawnDepth' => 1,
        ]),
        new Node('stage-1', EchoStep::class, stage: 1, inputs: ['value' => new FromResult('stage-0')]),
    ]));

    runStep($running->id, 'stage-0');
    $engine->cooldown();

    $held = $engine->start(new Workflow([
        new Node('held-step', EchoStep::class, inputs: ['value' => 'later']),
    ]));

    expect($held->fresh()->status)->toBe('held')
        ->and(statesOf($held->id))->toBe(['held-step' => 'pending']);

    $stage0 = $running->steps()->where('key', 'stage-0')->sole();
    $leaf = $running->steps()->where('key', $stage0->id.'/child-1')->sole();
    runStep($running->id, $leaf->key);
    runStep($running->id, 'stage-0');
    runStep($running->id, 'stage-1');

    expect($running->fresh()->status)->toBe('completed')
        ->and(app(PayloadCodec::class)->decode($running->steps()->where('key', 'stage-1')->sole()->result))->toBe('expanded')
        ->and($held->fresh()->status)->toBe('held');

    $engine->reopen();
    runStep($held->id, 'held-step');

    expect($held->fresh()->status)->toBe('completed');
});

it('handles before() skip/stop/fail/retry decisions without prematurely executing handle()', function (string $mode, string $expectedStepState, string $expectedWorkflowStatus) {
    $flow = app(Engine::class)->start(new Workflow([
        new Node('guarded', DispatcherParityStep::class, inputs: ['name' => 'guarded', 'mode' => $mode], execution: new ExecutionOptions(attempts: 2, backoffSeconds: [0])),
        new Node('after', EchoStep::class, stage: 1, inputs: ['value' => 'next']),
    ]));

    runStep($flow->id, 'guarded');
    if ($expectedStepState === 'completed') {
        expect(statesOf($flow->id)['guarded'])->toBe('queued')
            ->and(DispatcherParityStep::$events)->toBe([['before', 'guarded', $mode]]);
        runStep($flow->id, 'guarded');
        expect(DispatcherParityStep::$events)->toBe([
            ['before', 'guarded', $mode],
            ['before', 'guarded', $mode],
            ['handle', 'guarded', $mode],
        ]);
    } else {
        expect(array_column(DispatcherParityStep::$events, 0))->not->toContain('handle');
    }

    if (in_array($expectedStepState, ['skipped', 'completed'], true)) {
        runStep($flow->id, 'after');
    } else {
        $failure = $flow->failures()->sole();
        app(Scarlett\Failures\Runner::class)->run($failure->id, $failure->attempt);
    }

    expect(statesOf($flow->id)['guarded'])->toBe($expectedStepState)
        ->and($flow->fresh()->status)->toBe($expectedWorkflowStatus);
})->with([
    'skip' => ['before-skip', 'skipped', 'completed'],
    'stop' => ['before-stop', 'stopped', 'failed'],
    'fail' => ['before-fail', 'failed', 'failed'],
    'retry once' => ['before-retry-once', 'completed', 'completed'],
]);

it('recovers exceptions in onException() via complete() or children() without activating failure hooks', function (string $mode) {
    $flow = app(Engine::class)->start(new Workflow([
        new Node('worker', DispatcherParityStep::class, inputs: [
            'name' => 'worker',
            'mode' => $mode,
            'value' => $mode === 'throw-resolve' ? 'from-complete' : 'from-fallback-child',
        ]),
        new Node('rescue', EchoStep::class, rescues: 'worker', inputs: ['value' => 'unused-rescue']),
        new Node('after', EchoStep::class, stage: 1, inputs: ['value' => new FromResult('worker')]),
    ]));

    runStep($flow->id, 'worker');
    if ($mode === 'throw-children') {
        $worker = $flow->steps()->where('key', 'worker')->sole();
        runStep($flow->id, $worker->id.'/fallback-leaf');
        runStep($flow->id, 'worker');
    }
    runStep($flow->id, 'after');

    $expected = $mode === 'throw-resolve' ? 'from-complete' : 'from-fallback-child';

    expect($flow->fresh()->status)->toBe('completed')
        ->and(statesOf($flow->id)['rescue'])->toBe('skipped')
        ->and(DispatcherParityStep::$hookCalls)->toBe(0)
        ->and(app(PayloadCodec::class)->decode($flow->steps()->where('key', 'after')->sole()->result))->toBe($expected);
})->with([
    'resolve with value' => ['throw-resolve'],
    'resolve with child plan' => ['throw-children'],
]);

it('ignores obsolete or duplicate ExecuteStep transport deliveries on settled or recovered steps', function () {
    $engine = app(Engine::class);
    $flow = $engine->start(new Workflow([
        new Node('step', DispatcherParityStep::class, inputs: ['name' => 'step', 'mode' => 'value', 'value' => 'ok'], execution: new ExecutionOptions(attempts: 2, backoffSeconds: [0])),
    ]));

    $step = $flow->steps()->sole();
    $firstAttempt = $step->attempt;

    $engine->claim($step->id, $firstAttempt);
    $step->update(['lease_until' => now()->subSecond()]);
    $engine->recover($step->id);

    $secondAttempt = $step->fresh()->attempt;
    expect($secondAttempt)->not->toBe($firstAttempt);

    (new ExecuteStep($step->id, $firstAttempt))->handle(app(Runner::class));
    expect((string) $step->fresh()->state)->toBe('queued')
        ->and(DispatcherParityStep::$events)->toBe([]);

    (new ExecuteStep($step->id, $secondAttempt))->handle(app(Runner::class));
    (new ExecuteStep($step->id, $secondAttempt))->handle(app(Runner::class));

    expect($flow->fresh()->status)->toBe('completed')
        ->and(DispatcherParityStep::$events)->toBe([
            ['before', 'step', 'value'],
            ['handle', 'step', 'value'],
        ]);
});

it('executes past-due and immediate parallel siblings immediately while holding future notBefore siblings and blocking stage 1', function () {
    $flow = app(Engine::class)->start(new Workflow([
        new Node('past', ControlledStep::class, inputs: ['name' => 'past'], notBefore: now()->subSeconds(5)),
        controlled('now'),
        new Node('future', ControlledStep::class, inputs: ['name' => 'future'], notBefore: now()->addSeconds(30)),
        controlled('after', stage: 1),
    ]));

    runStep($flow->id, 'past');
    runStep($flow->id, 'now');
    runStep($flow->id, 'future');

    expect(statesOf($flow->id))->toBe([
        'after' => 'pending',
        'future' => 'queued',
        'now' => 'completed',
        'past' => 'completed',
    ])->and(ControlledStep::$actions)->toBe(['past' => 1, 'now' => 1]);

    $this->travel(30)->seconds();
    runStep($flow->id, 'future');
    runStep($flow->id, 'after');

    expect($flow->fresh()->status)->toBe('completed')
        ->and(ControlledStep::$actions)->toBe(['past' => 1, 'now' => 1, 'future' => 1, 'after' => 1]);
});

it('queries workflows cleanly across statuses and batch-reads active SubjectSlot assignments without cross-subject leakage', function () {
    $engine = app(Engine::class);

    $completed = $engine->start(new Workflow([controlled('a')], subjectType: 'position', subjectId: 'pos-1'));
    runStep($completed->id, 'a');

    $failed = $engine->start(new Workflow([controlled('a', 'fail')], subjectType: 'position', subjectId: 'pos-1'));
    runStep($failed->id, 'a');

    $running = $engine->replace(new SubjectSlot('position', 'pos-1', 'sync'), fn (): Workflow => new Workflow([controlled('a')]));
    $other = $engine->replace(new SubjectSlot('position', 'pos-2', 'sync'), fn (): Workflow => new Workflow([controlled('a')]));
    $emptyOtherType = $engine->replace(new SubjectSlot('account', 'pos-1', 'sync'), fn (): Workflow => new Workflow([controlled('a')]));

    $forPositionOne = WorkflowRecord::where('subject_type', 'position')->where('subject_id', 'pos-1')->pluck('status', 'id')->all();

    $expectedStatuses = [
        $completed->id => 'completed',
        $failed->id => 'failed',
        $running->id => 'running',
    ];
    ksort($forPositionOne);
    ksort($expectedStatuses);

    expect($forPositionOne)->toBe($expectedStatuses)->and($engine->currentForSubjects('position', ['pos-1', 'pos-2', 'pos-missing'], 'sync'))->toBe([
        'pos-1' => $running->id,
        'pos-2' => $other->id,
    ])->and($engine->current(new SubjectSlot('account', 'pos-1', 'sync')))->toBe($emptyOtherType->id);
});

it('refuses to archive or purge workflows while any branch step or unfinished durable failure hook keeps the graph unsettled', function () {
    $flow = app(Engine::class)->start(new Workflow([
        new Node('parent', FailureAwareStep::class, inputs: ['name' => 'parent', 'mode' => 'fail']),
    ]));

    runStep($flow->id, 'parent');
    expect($flow->fresh()->finished_at)->toBeNull();

    $flow->update(['updated_at' => now()->subDays(40), 'created_at' => now()->subDays(40)]);
    $this->artisan('scarlett:archive', ['--days' => '1'])->expectsOutput('Archived 0 workflow(s).')->assertSuccessful();
    expect($flow->fresh()->archived_at)->toBeNull();

    $failure = $flow->failures()->sole();
    app(Scarlett\Failures\Runner::class)->run($failure->id, $failure->attempt);
    $flow->update(['finished_at' => now()->subDays(2)]);

    $this->artisan('scarlett:archive', ['--days' => '1'])->expectsOutput('Archived 1 workflow(s).')->assertSuccessful();
    $flow->update(['archived_at' => now()->subDays(2)]);
    $this->artisan('scarlett:purge', ['--days' => '1'])->expectsOutput('Purged 1 workflow(s).')->assertSuccessful();
    expect(WorkflowRecord::find($flow->id))->toBeNull();
});
