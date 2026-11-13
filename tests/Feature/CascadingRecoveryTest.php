<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Scarlett\Definition\Node;
use Scarlett\Definition\Workflow;
use Scarlett\Engine;
use Scarlett\Execution\Decision;
use Scarlett\States\Completed;
use Scarlett\Tests\Fixtures\ControlledStep;
use Scarlett\Tests\Fixtures\ThrottlingStep;

beforeEach(function () {
    Queue::fake();
    $this->freezeSecond();
    ControlledStep::$actions = [];
    ControlledStep::$constructed = [];
});

it('drains parallel and sequential child rescues before activating parent recovery', function (string $outcome, bool $reversed) {
    $engine = app(Engine::class);
    $unrelated = $engine->start(new Workflow([controlled('unrelated')]));
    $nodes = [
        controlled('0'), controlled('child', $outcome, parent: '0'),
        controlled('unused', parent: '0'), controlled('later-child', stage: 1, parent: '0'),
        controlled('parallel-a', rescues: 'child'), controlled('parallel-b', rescues: 'child'),
        controlled('repair', parent: 'parallel-a'), controlled('join', stage: 1, rescues: 'child'),
        controlled('parent-rescue', rescues: '0'), controlled('after', stage: 1),
    ];
    $flow = $engine->start(new Workflow($reversed ? array_reverse($nodes) : $nodes));
    runStep($flow->id, '0');
    expect(statesOf($flow->id)['parent-rescue'])->toBe('pending');
    $unused = $flow->steps()->where('key', 'unused')->sole();
    runStep($flow->id, 'child');
    expect(statesOf($flow->id))->toBe([
        '0' => 'waitingforchildren', 'after' => 'pending', 'child' => $outcome === 'fail' ? 'failed' : 'stopped',
        'join' => 'pending', 'later-child' => 'cancelled', 'parallel-a' => 'queued', 'parallel-b' => 'queued',
        'parent-rescue' => 'pending', 'repair' => 'pending', 'unused' => 'cancelled',
    ]);
    expect($engine->claim($unused->id, $unused->attempt))->toBeNull();
    runStep($flow->id, 'parallel-a');
    runStep($flow->id, 'parallel-b');
    expect(statesOf($flow->id)['join'])->toBe('pending');
    runStep($flow->id, 'repair');
    expect(statesOf($flow->id)['join'])->toBe('queued')
        ->and(statesOf($flow->id)['parent-rescue'])->toBe('pending');
    runStep($flow->id, 'join');
    expect(statesOf($flow->id)['0'])->toBe('failed')
        ->and(statesOf($flow->id)['parent-rescue'])->toBe('queued')
        ->and(statesOf($flow->id)['after'])->toBe('cancelled');
    runStep($flow->id, 'parent-rescue');
    runStep($flow->id, 'parent-rescue');
    expect($flow->fresh()->status)->toBe('failed')
        ->and(ControlledStep::$actions['parent-rescue'])->toBe(1)
        ->and(array_column(ControlledStep::$constructed, 0))->not->toContain('unused', 'later-child', 'after')
        ->and(statesOf($unrelated->id))->toBe(['unrelated' => 'queued']);
})->with(['fail', 'stop'])->with([false, true]);

it('waits for recovery of a failed rescue and cancels its later stages', function (bool $reversed) {
    $nodes = [
        controlled('parent'), controlled('child', 'fail', parent: 'parent'),
        controlled('rescue', rescues: 'child'), controlled('repair', 'fail', parent: 'rescue'),
        controlled('repair-fallback', rescues: 'repair'), controlled('rescue-fallback', rescues: 'rescue'),
        controlled('later-rescue', stage: 1, rescues: 'child'), controlled('parent-rescue', rescues: 'parent'),
    ];
    $flow = app(Engine::class)->start(new Workflow($reversed ? array_reverse($nodes) : $nodes));
    foreach (['parent', 'child', 'rescue', 'repair'] as $key) {
        runStep($flow->id, $key);
    }
    expect(statesOf($flow->id))->toBe([
        'child' => 'failed', 'later-rescue' => 'pending', 'parent' => 'waitingforchildren',
        'parent-rescue' => 'pending', 'repair' => 'failed', 'repair-fallback' => 'queued',
        'rescue' => 'waitingforchildren', 'rescue-fallback' => 'pending',
    ]);
    runStep($flow->id, 'repair-fallback');
    expect(statesOf($flow->id)['rescue'])->toBe('failed')
        ->and(statesOf($flow->id)['rescue-fallback'])->toBe('queued')
        ->and(statesOf($flow->id)['later-rescue'])->toBe('cancelled')
        ->and(statesOf($flow->id)['parent-rescue'])->toBe('pending');
    runStep($flow->id, 'rescue-fallback');
    expect(statesOf($flow->id)['parent'])->toBe('failed')
        ->and(statesOf($flow->id)['parent-rescue'])->toBe('queued');
    runStep($flow->id, 'parent-rescue');
    expect($flow->fresh()->status)->toBe('failed');
})->with([false, true]);

it('waits for already-running siblings and their own recovery before parent recovery', function (bool $siblingFails) {
    $engine = app(Engine::class);
    $flow = $engine->start(new Workflow([
        controlled('parent'), controlled('first', 'fail', parent: 'parent'), controlled('sibling', parent: 'parent'),
        controlled('first-rescue', rescues: 'first'), controlled('sibling-rescue', rescues: 'sibling'),
        controlled('parent-rescue', rescues: 'parent'),
    ]));
    runStep($flow->id, 'parent');
    $sibling = $flow->steps()->where('key', 'sibling')->sole();
    $engine->claim($sibling->id, $sibling->attempt);
    runStep($flow->id, 'first');
    runStep($flow->id, 'first-rescue');
    expect(statesOf($flow->id)['parent'])->toBe('waitingforchildren')
        ->and(statesOf($flow->id)['sibling'])->toBe('running')
        ->and(statesOf($flow->id)['parent-rescue'])->toBe('pending');
    if ($siblingFails) {
        $engine->decide($sibling->id, $sibling->attempt, Decision::fail('Another child failed.'));
        expect(statesOf($flow->id)['sibling-rescue'])->toBe('queued')
            ->and(statesOf($flow->id)['parent-rescue'])->toBe('pending');
        runStep($flow->id, 'sibling-rescue');
    } else {
        $engine->saveAction($sibling->id, $sibling->attempt, null, Completed::class);
        $engine->finish($sibling->id, $sibling->attempt);
        expect(statesOf($flow->id)['sibling-rescue'])->toBe('skipped');
    }
    expect(statesOf($flow->id)['parent-rescue'])->toBe('queued');
    runStep($flow->id, 'parent-rescue');
    expect($flow->fresh()->status)->toBe('failed');
})->with([false, true]);

it('does not activate recovery for successful skipped or cancelled children', function (string $outcome) {
    $engine = app(Engine::class);
    $flow = $engine->start(new Workflow([
        controlled('parent'), controlled('child', $outcome, parent: 'parent'),
        controlled('child-rescue', rescues: 'child'), controlled('parent-rescue', rescues: 'parent'),
    ]));
    runStep($flow->id, 'parent');
    expect(statesOf($flow->id)['child'])->toBe('queued');
    if ($outcome === 'cancel') {
        $engine->cancel($flow->steps()->where('key', 'child')->sole()->id);
    } else {
        runStep($flow->id, 'child');
    }
    expect(statesOf($flow->id))->toBe([
        'child' => match ($outcome) {
            'success' => 'completed', 'skip' => 'skipped', 'cancel' => 'cancelled'
        },
        'child-rescue' => 'skipped', 'parent' => $outcome === 'cancel' ? 'cancelled' : 'completed',
        'parent-rescue' => 'skipped',
    ])->and($flow->fresh()->status)->toBe($outcome === 'cancel' ? 'cancelled' : 'completed');
    expect(array_column(ControlledStep::$constructed, 0))->not->toContain('child-rescue', 'parent-rescue');
})->with(['success', 'skip', 'cancel']);

it('keeps ancestors waiting through a throttled resolver without consuming its retries', function () {
    ThrottlingStep::$blocked = true;
    ThrottlingStep::$actions = 0;
    $flow = app(Engine::class)->start(new Workflow([
        controlled('parent'), controlled('child', 'fail', parent: 'parent'),
        new Node('rescue', ThrottlingStep::class, inputs: ['source' => 'policy', 'seconds' => 7], rescues: 'child'),
        controlled('parent-rescue', rescues: 'parent'),
    ]));
    runStep($flow->id, 'parent');
    runStep($flow->id, 'child');
    expect(statesOf($flow->id)['parent-rescue'])->toBe('pending');
    runStep($flow->id, 'rescue');
    $rescue = $flow->steps()->where('key', 'rescue')->sole();
    expect($rescue->failures)->toBe(0)->and($rescue->available_at->equalTo(now()->addSeconds(7)))->toBeTrue();
    ThrottlingStep::$blocked = false;
    runStep($flow->id, 'rescue');
    expect(statesOf($flow->id)['parent'])->toBe('waitingforchildren');
    $this->travel(7)->seconds();
    runStep($flow->id, 'rescue');
    expect(statesOf($flow->id)['parent-rescue'])->toBe('queued')
        ->and(ThrottlingStep::$actions)->toBe(1);
    runStep($flow->id, 'parent-rescue');
    expect($flow->fresh()->status)->toBe('failed');
});

it('revokes a recovering subtree without admitting ancestor rescues or accepting stale outcomes', function () {
    $engine = app(Engine::class);
    $flow = $engine->start(new Workflow([
        controlled('parent'), controlled('child', 'fail', parent: 'parent'),
        controlled('rescue', rescues: 'child'), controlled('parent-rescue', rescues: 'parent'),
    ]));
    runStep($flow->id, 'parent');
    runStep($flow->id, 'child');
    $rescue = $flow->steps()->where('key', 'rescue')->sole();
    $engine->claim($rescue->id, $rescue->attempt);
    $engine->saveAction($rescue->id, $rescue->attempt, null, Completed::class);
    expect(statesOf($flow->id)['parent'])->toBe('waitingforchildren');
    expect($engine->cancelWorkflow($flow->id))->toBeTrue();
    expect($engine->finish($rescue->id, $rescue->attempt))->toBeFalse();
    expect(statesOf($flow->id))->toBe([
        'child' => 'failed', 'parent' => 'cancelled', 'parent-rescue' => 'cancelled', 'rescue' => 'cancelled',
    ])->and($flow->fresh()->status)->toBe('failed');
    $parentRescue = $flow->steps()->where('key', 'parent-rescue')->sole();
    expect(DB::table('scarlett_deliveries')->where('step_id', $parentRescue->id)->exists())->toBeFalse();
});

it('drains every level of a deep cascade exactly once regardless of declaration order', function (int $depth, bool $reversed) {
    $nodes = [];
    for ($level = 0; $level < $depth; $level++) {
        $nodes[] = controlled('parent-'.$level, parent: $level === 0 ? null : 'parent-'.($level - 1));
        $nodes[] = controlled('rescue-'.$level, rescues: 'parent-'.$level);
    }
    $nodes[] = controlled('leaf', 'fail', parent: 'parent-'.($depth - 1));
    $nodes[] = controlled('leaf-rescue', rescues: 'leaf');
    $nodes[] = controlled('after', stage: 1);
    $flow = app(Engine::class)->start(new Workflow($reversed ? array_reverse($nodes) : $nodes));
    for ($level = 0; $level < $depth; $level++) {
        runStep($flow->id, 'parent-'.$level);
    }
    runStep($flow->id, 'leaf');
    expect($flow->steps()->where('state', 'waitingforchildren')->count())->toBe($depth);
    $rescues = ['leaf-rescue'];
    for ($level = $depth - 1; $level >= 0; $level--) {
        $rescues[] = 'rescue-'.$level;
    }
    foreach ($rescues as $index => $key) {
        expect($flow->steps()->where('state', 'queued')->pluck('key')->all())->toBe([$key]);
        if (isset($rescues[$index + 1])) {
            runStep($flow->id, $rescues[$index + 1]);
            expect(statesOf($flow->id)[$rescues[$index + 1]])->toBe('pending');
        }
        runStep($flow->id, $key);
        runStep($flow->id, $key);
        $step = $flow->steps()->where('key', $key)->sole();
        expect(ControlledStep::$actions[$key])->toBe(1)
            ->and(DB::table('scarlett_deliveries')->where('step_id', $step->id)->count())->toBe(1);
    }
    expect($flow->fresh()->status)->toBe('failed')
        ->and(statesOf($flow->id)['after'])->toBe('cancelled');
})->with([1, 4, 12])->with([false, true]);

it('keeps recovery cascading after resolver retry or verification exhaustion', function (string $mode) {
    config([
        'scarlett.retry.attempts' => 2, 'scarlett.retry.backoff_seconds' => [3],
        'scarlett.verification.attempts' => 2, 'scarlett.verification.backoff_seconds' => [3],
    ]);
    $flow = app(Engine::class)->start(new Workflow([
        controlled('parent'), controlled('child', 'fail', parent: 'parent'),
        controlled('rescue', $mode, rescues: 'child'), controlled('fallback', rescues: 'rescue'),
        controlled('parent-rescue', rescues: 'parent'),
    ]));
    runStep($flow->id, 'parent');
    runStep($flow->id, 'child');
    expect(statesOf($flow->id)['rescue'])->toBe('queued');
    runStep($flow->id, 'rescue');
    expect(statesOf($flow->id))->toBe([
        'child' => 'failed', 'fallback' => 'pending', 'parent' => 'waitingforchildren',
        'parent-rescue' => 'pending', 'rescue' => 'queued',
    ]);
    $this->travel(3)->seconds();
    runStep($flow->id, 'rescue');
    expect(statesOf($flow->id))->toBe([
        'child' => 'failed', 'fallback' => 'queued', 'parent' => 'waitingforchildren',
        'parent-rescue' => 'pending', 'rescue' => 'failed',
    ])->and(ControlledStep::$actions['rescue'])->toBe($mode === 'throw' ? 2 : 1);
    runStep($flow->id, 'fallback');
    expect(statesOf($flow->id)['parent-rescue'])->toBe('queued');
    runStep($flow->id, 'parent-rescue');
    expect($flow->fresh()->status)->toBe('failed');
})->with(['throw', 'verify']);

it('resumes cascading recovery after an exhausted resolver lease without accepting its old worker', function () {
    config(['scarlett.retry.attempts' => 1]);
    $engine = app(Engine::class);
    $flow = $engine->start(new Workflow([
        controlled('parent'), controlled('child', 'fail', parent: 'parent'),
        controlled('rescue', rescues: 'child'), controlled('fallback', rescues: 'rescue'),
        controlled('parent-rescue', rescues: 'parent'),
    ]));
    runStep($flow->id, 'parent');
    runStep($flow->id, 'child');
    $rescue = $flow->steps()->where('key', 'rescue')->sole();
    $engine->claim($rescue->id, $rescue->attempt);
    expect(statesOf($flow->id)['parent'])->toBe('waitingforchildren');
    $this->travel($rescue->executionOptions()['lease_seconds'] + 1)->seconds();
    expect($engine->resume($flow->id))->toBe(1);
    expect(statesOf($flow->id))->toBe([
        'child' => 'failed', 'fallback' => 'queued', 'parent' => 'waitingforchildren',
        'parent-rescue' => 'pending', 'rescue' => 'failed',
    ])->and($engine->saveAction($rescue->id, $rescue->attempt, null, Completed::class))->toBeFalse();
    runStep($flow->id, 'fallback');
    expect(statesOf($flow->id)['parent-rescue'])->toBe('queued');
    runStep($flow->id, 'parent-rescue');
    expect($flow->fresh()->status)->toBe('failed');
});
