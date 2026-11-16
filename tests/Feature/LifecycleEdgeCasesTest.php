<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Scarlett\Definition\ExecutionOptions;
use Scarlett\Definition\Node;
use Scarlett\Definition\Workflow;
use Scarlett\Engine;
use Scarlett\Exceptions\Throttled;
use Scarlett\Execution\Context;
use Scarlett\Execution\Decision;
use Scarlett\Execution\Failure;
use Scarlett\Execution\FailureCause;
use Scarlett\Execution\Journal;
use Scarlett\Execution\Progression;
use Scarlett\Failures\Coordinator;
use Scarlett\Failures\Runner;
use Scarlett\Failures\Status;
use Scarlett\History;
use Scarlett\Inputs\PayloadCodec;
use Scarlett\Inputs\Service;
use Scarlett\Policies\InputExceptionHandler;
use Scarlett\States\Cancelled;
use Scarlett\States\Completed;
use Scarlett\States\Pending;
use Scarlett\States\Phase;
use Scarlett\States\Running;
use Scarlett\States\StateRegistry;
use Scarlett\States\StepState;
use Scarlett\Tests\Fixtures\ControlledStep;
use Scarlett\Tests\Fixtures\CustomFailedState;
use Scarlett\Tests\Fixtures\FallbackOnlyFailureStep;
use Scarlett\Tests\Fixtures\LifecycleEdgeStep;

beforeEach(function () {
    Queue::fake();
    $this->freezeSecond();
    ControlledStep::$actions = [];
    LifecycleEdgeStep::$actions = 0;
    LifecycleEdgeStep::$verifications = 0;
    FallbackOnlyFailureStep::$calls = [];
});

it('progresses multi-entry backoff schedules and clamps beyond the final entry for action and verification retries', function () {
    $flow = app(Engine::class)->start(new Workflow([
        new Node('action-retry', ControlledStep::class, inputs: ['name' => 'action-retry', 'mode' => 'throw'], execution: new ExecutionOptions(attempts: 5, backoffSeconds: [2, 5, 10])),
        new Node('verify-retry', ControlledStep::class, inputs: ['name' => 'verify-retry', 'mode' => 'verify'], execution: new ExecutionOptions(verificationAttempts: 4, verificationBackoffSeconds: [3, 9])),
    ]));

    $actionDelays = [];
    for ($attempt = 1; $attempt <= 4; $attempt++) {
        runStep($flow->id, 'action-retry');
        $step = $flow->steps()->where('key', 'action-retry')->sole();
        $actionDelays[] = (int) now()->diffInSeconds($step->available_at);
        $this->travel($actionDelays[$attempt - 1])->seconds();
    }
    runStep($flow->id, 'action-retry');

    expect($actionDelays)->toBe([2, 5, 10, 10])
        ->and((string) $flow->steps()->where('key', 'action-retry')->sole()->state)->toBe('failed');

    $verifyDelays = [];
    for ($attempt = 1; $attempt <= 3; $attempt++) {
        runStep($flow->id, 'verify-retry');
        $step = $flow->steps()->where('key', 'verify-retry')->sole();
        $verifyDelays[] = (int) now()->diffInSeconds($step->available_at);
        $this->travel($verifyDelays[$attempt - 1])->seconds();
    }
    runStep($flow->id, 'verify-retry');

    expect($verifyDelays)->toBe([3, 9, 9])
        ->and((string) $flow->steps()->where('key', 'verify-retry')->sole()->state)->toBe('failed')
        ->and(ControlledStep::$actions['verify-retry'])->toBe(1);
});

it('retries verification on thrown exceptions without re-running handle and exhausts to failed', function () {
    $flow = app(Engine::class)->start(new Workflow([
        new Node('verify-throw', LifecycleEdgeStep::class, inputs: ['mode' => 'verify-throw'], execution: new ExecutionOptions(verificationAttempts: 2, verificationBackoffSeconds: [4])),
        controlled('after', stage: 1),
    ]));

    runStep($flow->id, 'verify-throw');
    $step = $flow->steps()->where('key', 'verify-throw')->sole();
    expect((string) $step->state)->toBe('queued')
        ->and($step->verifications)->toBe(1)
        ->and($step->failures)->toBe(0)
        ->and($step->error)->toBe('Verification exploded.')
        ->and(LifecycleEdgeStep::$actions)->toBe(1);

    $this->travel(4)->seconds();
    runStep($flow->id, 'verify-throw');
    expect(statesOf($flow->id))->toBe(['after' => 'cancelled', 'verify-throw' => 'failed'])
        ->and(LifecycleEdgeStep::$actions)->toBe(1)
        ->and(LifecycleEdgeStep::$verifications)->toBe(2);
});

it('completes directly from before() without invoking handle() while still running verify()', function () {
    $flow = app(Engine::class)->start(new Workflow([
        new Node('pre-complete', LifecycleEdgeStep::class, inputs: ['mode' => 'before-complete', 'value' => ['done' => true]]),
    ]));

    runStep($flow->id, 'pre-complete');
    $step = $flow->steps()->sole();
    expect($flow->fresh()->status)->toBe('completed')
        ->and(LifecycleEdgeStep::$actions)->toBe(0)
        ->and(LifecycleEdgeStep::$verifications)->toBe(1)
        ->and(app(PayloadCodec::class)->decode($step->result))->toBe(['done' => true]);
});

it('settles immediately when onException returns skip or stop without spending retry budget', function (string $mode, string $expectedState, string $expectedWorkflowStatus) {
    $flow = app(Engine::class)->start(new Workflow([
        new Node('step', LifecycleEdgeStep::class, inputs: ['mode' => $mode], execution: new ExecutionOptions(attempts: 3)),
        controlled('after', stage: 1),
    ]));

    runStep($flow->id, 'step');
    $step = $flow->steps()->where('key', 'step')->sole();
    expect((string) $step->state)->toBe($expectedState)
        ->and($step->failures)->toBe(0)
        ->and((string) $flow->steps()->where('key', 'after')->sole()->state)->toBe($mode === 'exception-skip' ? 'queued' : 'cancelled');

    if ($mode === 'exception-skip') {
        runStep($flow->id, 'after');
    }
    expect($flow->fresh()->status)->toBe($expectedWorkflowStatus);
})->with([
    'skip' => ['exception-skip', 'skipped', 'completed'],
    'stop' => ['exception-stop', 'stopped', 'failed'],
]);

it('rejects InputExceptionHandler returning complete or children decisions', function (string $handlerClass) {
    config(['scarlett.input_exception_handler' => $handlerClass]);
    app()->bind('failing.input.service', fn () => throw new RuntimeException('Service down.'));

    $flow = app(Engine::class)->start(new Workflow([
        new Node('broken', ControlledStep::class, inputs: ['name' => 'broken', 'value' => new Service('failing.input.service')]),
    ]));

    runStep($flow->id, 'broken');
    $step = $flow->steps()->sole();
    expect((string) $step->state)->toBe('failed')
        ->and($step->error)->toContain('unconstructed step')
        ->and($flow->steps()->count())->toBe(1);
})->with([
    'complete' => [InvalidCompleteInputExceptionHandler::class],
    'children' => [InvalidChildrenInputExceptionHandler::class],
]);

it('rejects negative delays on Decision::retry, Decision::throttle and Throttled', function () {
    expect(fn () => Decision::retry(-1))->toThrow(InvalidArgumentException::class, 'negative');
    expect(fn () => Decision::throttle(-1))->toThrow(InvalidArgumentException::class, 'negative');
    expect(fn () => new Throttled(-1))->toThrow(InvalidArgumentException::class, 'negative');
});

it('rejects Failure::literalInput for non-literal or missing bindings', function () {
    $failure = new Failure(
        id: 'fail-1',
        cause: FailureCause::Decided,
        reason: 'Boom',
        stepKey: 'step',
        stepClass: ControlledStep::class,
        bindings: [
            'service' => ['kind' => 'service', 'class' => stdClass::class],
            'result' => ['kind' => 'result', 'step' => 'prev'],
        ],
        subjectType: null,
        subjectId: null,
        metadata: [],
    );

    expect(fn () => $failure->literalInput('service'))->toThrow(InvalidArgumentException::class, 'not a stored literal');
    expect(fn () => $failure->literalInput('result'))->toThrow(InvalidArgumentException::class, 'not a stored literal');
    expect(fn () => $failure->literalInput('missing'))->toThrow(InvalidArgumentException::class, 'not a stored literal');
});

it('reports Context::canRetry() accurately at final attempts and expired leases for both step and failure hooks', function () {
    $engine = app(Engine::class);
    $flow = $engine->start(new Workflow([
        new Node('step', FallbackOnlyFailureStep::class, inputs: ['label' => 'single'], execution: new ExecutionOptions(attempts: 1)),
    ]));
    $step = $flow->steps()->sole();
    $engine->claim($step->id, $step->attempt);

    $stepContext = new Context($flow->id, $step->id, $step->attempt, 0);
    expect($stepContext->canRetry())->toBeFalse();

    $multi = $engine->start(new Workflow([
        new Node('multi', FallbackOnlyFailureStep::class, inputs: ['label' => 'multi'], execution: new ExecutionOptions(attempts: 2, backoffSeconds: [0])),
    ]));
    $multiStep = $multi->steps()->sole();
    $engine->claim($multiStep->id, $multiStep->attempt);
    $multiContext = new Context($multi->id, $multiStep->id, $multiStep->attempt, 0);
    expect($multiContext->canRetry())->toBeTrue();
    $multiStep->update(['lease_until' => now()->subSecond()]);
    expect($multiContext->canRetry())->toBeFalse();

    $failedFlow = $engine->start(new Workflow([
        new Node('failing', FallbackOnlyFailureStep::class, inputs: ['label' => 'single'], execution: new ExecutionOptions(attempts: 1)),
    ]));
    $failedStep = $failedFlow->steps()->sole();
    runStep($failedFlow->id, 'failing');
    $failure = $failedFlow->failures()->sole();
    app(Coordinator::class)->claim($failure->id, $failure->attempt);
    $failureContext = new Context($failedFlow->id, $failedStep->id, $failure->attempt, 0, failureId: $failure->id);
    expect($failureContext->canRetry())->toBeFalse();
});

it('delegates Step::onFailure() to static::onFailureWithoutInstance() by default', function () {
    $flow = app(Engine::class)->start(new Workflow([
        new Node('fallback', FallbackOnlyFailureStep::class, inputs: ['label' => 'delegated-ok']),
    ]));

    runStep($flow->id, 'fallback');
    $failure = $flow->failures()->sole();
    app(Runner::class)->run($failure->id, $failure->attempt);

    expect($failure->fresh()->status)->toBe(Status::Completed)
        ->and(FallbackOnlyFailureStep::$calls)->toBe([['delegated-ok', null]])
        ->and($flow->fresh()->metadata)->toBe(['delegated' => 'delegated-ok']);
});

it('rejects invalid StateRegistry custom states and transitions', function (array $states, array $transitions, string $message) {
    config(['scarlett.states' => $states, 'scarlett.transitions' => $transitions]);
    expect(fn () => app(StateRegistry::class)->transitions())->toThrow(InvalidArgumentException::class, $message);
})->with([
    'non-StepState subclass' => [[stdClass::class], [], 'extend StepState'],
    'duplicate alias' => [[DuplicateAliasTestState::class], [], 'unique stable alias'],
    'missing explicit name' => [[MissingAliasTestState::class], [], 'unique stable alias'],
    'non-settled satisfiesDependency' => [[NonSettledDependencyTestState::class], [], 'Only settled states'],
    'non-settled activatesRecovery' => [[NonSettledRecoveryTestState::class], [], 'Only settled states'],
    'both dependency and recovery' => [[ContradictoryTestState::class], [], 'satisfy dependencies and activate recovery'],
    'transition from settled state' => [[], [[Completed::class, Pending::class]], 'cannot reopen settled work'],
]);

it('activates rescue branches and settles workflow as failed for custom activatesRecovery states', function () {
    config([
        'scarlett.states' => [CustomFailedState::class],
        'scarlett.transitions' => [[Running::class, CustomFailedState::class]],
    ]);

    $engine = app(Engine::class);
    $flow = $engine->start(new Workflow([
        new Node('owner', FallbackOnlyFailureStep::class, inputs: ['label' => 'custom-failed']),
        controlled('child', parent: 'owner'),
        controlled('rescue', rescues: 'owner'),
        controlled('after', stage: 1),
    ]));

    $owner = $flow->steps()->where('key', 'owner')->sole();
    $engine->claim($owner->id, $owner->attempt);

    DB::transaction(function () use ($flow, $owner): void {
        app(Journal::class)->transition($owner->fresh(), CustomFailedState::class, 'Custom recovery failure');
        app(Progression::class)->advance($flow->fresh());
    });

    expect((string) $flow->steps()->where('key', 'owner')->sole()->state)->toBe('customfailed')
        ->and((string) $flow->steps()->where('key', 'child')->sole()->state)->toBe('skipped')
        ->and((string) $flow->steps()->where('key', 'rescue')->sole()->state)->toBe('queued')
        ->and($flow->failures()->count())->toBe(1);

    runStep($flow->id, 'rescue');
    $failure = $flow->failures()->sole();
    app(Runner::class)->run($failure->id, $failure->attempt);

    expect($flow->fresh()->status)->toBe('failed')
        ->and((string) $flow->steps()->where('key', 'after')->sole()->state)->toBe('cancelled');
});

it('rejects runtime child plans whose resultFrom targets a nested grandchild or rescue node', function (array $planNodes, string $resultFrom) {
    $flow = app(Engine::class)->start(new Workflow([controlled('owner')]));
    $owner = $flow->steps()->where('key', 'owner')->sole();
    app(Engine::class)->claim($owner->id, $owner->attempt);

    expect(fn () => app(Engine::class)->decide($owner->id, $owner->attempt, Decision::children($planNodes, resultFrom: $resultFrom)))
        ->toThrow(InvalidArgumentException::class, 'direct child');
})->with([
    'grandchild' => [[controlled('child'), controlled('grandchild', parent: 'child')], 'grandchild'],
    'rescue node' => [[controlled('child'), controlled('rescue', rescues: 'child')], 'rescue'],
]);

it('settles a WaitingForChildren parent as Cancelled without re-verifying when a runtime child is cancelled', function () {
    $engine = app(Engine::class);
    $flow = $engine->start(new Workflow([
        new Node('parent', LifecycleEdgeStep::class, inputs: ['mode' => 'verify-throw']),
    ]));
    $parent = $flow->steps()->where('key', 'parent')->sole();
    $engine->claim($parent->id, $parent->attempt);
    $engine->decide($parent->id, $parent->attempt, Decision::children([controlled('runtime-child')]));

    $child = $flow->steps()->where('key', $parent->id.'/runtime-child')->sole();
    expect($engine->cancel($child->id))->toBeTrue();

    expect($parent->fresh()->state)->toBeInstanceOf(Cancelled::class)
        ->and($flow->fresh()->status)->toBe('cancelled')
        ->and(LifecycleEdgeStep::$verifications)->toBe(0);
});

it('waits for running siblings before settling a WaitingForChildren parent after cancellation', function () {
    $engine = app(Engine::class);
    $flow = $engine->start(new Workflow([
        controlled('parent'),
        controlled('running-child', parent: 'parent'),
        controlled('cancelled-child', parent: 'parent'),
        controlled('pending-child', parent: 'parent'),
    ]));

    runStep($flow->id, 'parent');
    $running = $flow->steps()->where('key', 'running-child')->sole();
    $cancelled = $flow->steps()->where('key', 'cancelled-child')->sole();
    expect($engine->claim($running->id, $running->attempt))->not->toBeNull();
    expect($engine->cancel($cancelled->id))->toBeTrue();

    expect(statesOf($flow->id))->toMatchArray([
        'parent' => 'waitingforchildren',
        'running-child' => 'running',
        'cancelled-child' => 'cancelled',
        'pending-child' => 'cancelled',
    ])->and($flow->fresh()->status)->toBe('running');

    expect($engine->saveAction($running->id, $running->attempt, null, Completed::class))->toBeTrue();
    expect($engine->finish($running->id, $running->attempt))->toBeTrue();
    expect(statesOf($flow->id))->toMatchArray([
        'parent' => 'cancelled',
        'running-child' => 'completed',
        'cancelled-child' => 'cancelled',
        'pending-child' => 'cancelled',
    ])->and($flow->fresh()->status)->toBe('cancelled');
});

it('deletes an active workflow via History::delete(..., includingRunning: true) and rejects non-positive batch sizes', function () {
    $flow = app(Engine::class)->start(new Workflow([
        new Node('fail-me', FallbackOnlyFailureStep::class, inputs: ['label' => 'x']),
        controlled('blocked', stage: 1),
    ]));
    runStep($flow->id, 'fail-me');

    $history = app(History::class);
    expect($history->delete($flow->id))->toBeFalse();
    expect($history->delete($flow->id, includingRunning: true))->toBeTrue();

    expect(DB::table('scarlett_workflows')->where('id', $flow->id)->exists())->toBeFalse()
        ->and(DB::table('scarlett_steps')->where('workflow_id', $flow->id)->exists())->toBeFalse()
        ->and(DB::table('scarlett_failures')->where('workflow_id', $flow->id)->exists())->toBeFalse();

    expect(fn () => $history->archiveBefore(now(), 0))->toThrow(InvalidArgumentException::class, 'positive integer');
    expect(fn () => $history->purge(now(), -1))->toThrow(InvalidArgumentException::class, 'positive integer');
});

final class DuplicateAliasTestState extends StepState
{
    public static string $name = 'completed';

    public static function phase(): Phase
    {
        return Phase::Settled;
    }

    public static function satisfiesDependency(): bool
    {
        return true;
    }
}

final class MissingAliasTestState extends StepState
{
    public static function phase(): Phase
    {
        return Phase::Pending;
    }
}

final class NonSettledDependencyTestState extends StepState
{
    public static string $name = 'non_settled_dependency';

    public static function phase(): Phase
    {
        return Phase::Running;
    }

    public static function satisfiesDependency(): bool
    {
        return true;
    }
}

final class NonSettledRecoveryTestState extends StepState
{
    public static string $name = 'non_settled_recovery';

    public static function phase(): Phase
    {
        return Phase::Queued;
    }

    public static function activatesRecovery(): bool
    {
        return true;
    }
}

final class ContradictoryTestState extends StepState
{
    public static string $name = 'contradictory_test_state';

    public static function phase(): Phase
    {
        return Phase::Settled;
    }

    public static function satisfiesDependency(): bool
    {
        return true;
    }

    public static function activatesRecovery(): bool
    {
        return true;
    }
}

final class InvalidCompleteInputExceptionHandler extends InputExceptionHandler
{
    public function decide(Throwable $exception, Context $context): Decision
    {
        return Decision::complete('should-not-complete');
    }
}

final class InvalidChildrenInputExceptionHandler extends InputExceptionHandler
{
    public function decide(Throwable $exception, Context $context): Decision
    {
        return Decision::children([controlled('should-not-expand')]);
    }
}
