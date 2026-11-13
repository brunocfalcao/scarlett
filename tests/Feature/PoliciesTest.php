<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Scarlett\Definition\Node;
use Scarlett\Definition\Workflow;
use Scarlett\Engine;
use Scarlett\Execution\Decision;
use Scarlett\Execution\Runner;
use Scarlett\Models\StepRecord;
use Scarlett\Tests\Fixtures\ControlledStep;
use Scarlett\Tests\Fixtures\OwnershipStep;

beforeEach(function () {
    ControlledStep::$actions = [];
    ControlledStep::$constructed = [];
    Queue::fake();
    $this->freezeSecond();
});
it('keeps retries bounded and refuses old deliveries without reconstructing the step', function () {
    config(['scarlett.retry.attempts' => 2, 'scarlett.retry.backoff_seconds' => [7]]);
    $workflow = app(Engine::class)->start(new Workflow([controlled('a', 'throw'), controlled('b', stage: 1)]));
    $first = $workflow->steps()->where('key', 'a')->sole();
    runStep($workflow->id, 'a');
    $next = $first->fresh();
    expect($next->failures)->toBe(1)->and((string) $next->state)->toBe('queued');
    expect($next->available_at->equalTo(now()->addSeconds(7)))->toBeTrue();
    app(Runner::class)->run($first->id, $first->attempt);
    runStep($workflow->id, 'a');
    expect(ControlledStep::$actions)->toBe(['a' => 1]);
    $this->travel(7)->seconds();
    runStep($workflow->id, 'a');
    expect(statesOf($workflow->id))->toBe(['a' => 'failed', 'b' => 'cancelled']);
    expect(ControlledStep::$actions)->toBe(['a' => 2]);
});
it('throttles without consuming failure retries', function () {
    $workflow = app(Engine::class)->start(new Workflow([controlled('a', 'throttle')]));
    runStep($workflow->id, 'a');
    $step = $workflow->steps()->sole();
    expect((string) $step->state)->toBe('queued')->and($step->failures)->toBe(0)
        ->and($step->available_at->equalTo(now()->addSeconds(10)))->toBeTrue();
});
it('retries verification without repeating the original business action', function () {
    config(['scarlett.verification.attempts' => 2, 'scarlett.verification.backoff_seconds' => [4]]);
    $workflow = app(Engine::class)->start(new Workflow([controlled('a', 'verify'), controlled('b', stage: 1)]));
    runStep($workflow->id, 'a');
    expect(statesOf($workflow->id))->toBe(['a' => 'queued', 'b' => 'pending']);
    $this->travel(4)->seconds();
    runStep($workflow->id, 'a');
    expect(statesOf($workflow->id))->toBe(['a' => 'failed', 'b' => 'cancelled']);
    expect(ControlledStep::$actions)->toBe(['a' => 1]);
    expect($workflow->steps()->where('key', 'a')->sole()->verifications)->toBe(2);
});
it('ignores duplicate and obsolete outcomes and does not cancel running work', function () {
    $engine = app(Engine::class);
    $workflow = $engine->start(new Workflow([controlled('a'), controlled('b', stage: 1)]));
    $step = $workflow->steps()->where('key', 'a')->sole();
    expect($engine->claim($step->id, $step->attempt)->id)->toBe($step->id);
    expect($engine->claim($step->id, $step->attempt))->toBeNull();
    expect($engine->cancel($step->id))->toBeFalse();
    expect($engine->decide($step->id, 'obsolete', Decision::fail()))->toBeFalse();
    expect(statesOf($workflow->id))->toBe(['a' => 'running', 'b' => 'pending']);
    expect($engine->decide($step->id, $step->attempt, Decision::stop()))->toBeTrue();
    expect($engine->decide($step->id, $step->attempt, Decision::fail()))->toBeFalse();
    expect(statesOf($workflow->id))->toBe(['a' => 'stopped', 'b' => 'cancelled']);
});
it('does not publish rolled-back workflow work', function () {
    DB::beginTransaction();
    $workflow = app(Engine::class)->start(new Workflow([controlled('a')]));
    Queue::assertNothingPushed();
    DB::rollBack();
    expect(StepRecord::where('workflow_id', $workflow->id)->exists())->toBeFalse();
    Queue::assertNothingPushed();
});
it('immediately propagates a child failure while preserving already-running siblings', function () {
    $engine = app(Engine::class);
    $workflow = $engine->start(new Workflow([controlled('p'), controlled('c', 'fail', parent: 'p'), controlled('s', parent: 'p'), controlled('next', stage: 1), controlled('rescue', rescues: 'p')]));
    runStep($workflow->id, 'p');
    $sibling = $workflow->steps()->where('key', 's')->sole();
    $engine->claim($sibling->id, $sibling->attempt);
    runStep($workflow->id, 'c');
    expect(statesOf($workflow->id))->toBe(['c' => 'failed', 'next' => 'cancelled', 'p' => 'failed', 'rescue' => 'queued', 's' => 'running']);
});
it('keeps a settled workflow immutable when obsolete notifications arrive', function () {
    $engine = app(Engine::class);
    $flow = $engine->start(new Workflow([controlled('a')]));
    $step = $flow->steps()->sole();
    runStep($flow->id, 'a');
    $finished = $flow->fresh()->finished_at;
    $this->travel(10)->seconds();
    expect($engine->decide($step->id, $step->attempt, Decision::fail()))->toBeFalse();
    expect($flow->fresh()->finished_at->equalTo($finished))->toBeTrue();
});
it('settles a cancelled held workflow without reopening admission', function () {
    $engine = app(Engine::class);
    $engine->cooldown();
    $flow = $engine->start(new Workflow([controlled('a')]));
    expect($flow->status)->toBe('held');
    expect($engine->cancel($flow->steps()->sole()->id))->toBeTrue();
    expect($flow->fresh()->status)->toBe('cancelled');
});
it('propagates cancellation through a held subtree before admission opens', function () {
    $engine = app(Engine::class);
    $engine->cooldown();
    $flow = $engine->start(new Workflow([controlled('parent'), controlled('child', parent: 'parent')]));
    expect(statesOf($flow->id))->toBe(['child' => 'pending', 'parent' => 'pending']);
    $engine->cancel($flow->steps()->where('key', 'parent')->sole()->id);
    expect(statesOf($flow->id))->toBe(['child' => 'cancelled', 'parent' => 'cancelled']);
    expect($flow->fresh()->status)->toBe('cancelled');
});
it('does not enter a later lifecycle hook after ownership is lost during throttling', function () {
    OwnershipStep::$prechecks = 0;
    $flow = app(Engine::class)->start(new Workflow([new Node('owned', OwnershipStep::class)]));
    runStep($flow->id, 'owned');
    expect(statesOf($flow->id))->toBe(['owned' => 'stopped']);
    expect(OwnershipStep::$prechecks)->toBe(0);
});
