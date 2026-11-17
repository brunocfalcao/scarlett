<?php

declare(strict_types=1);

/**
 * Exercises nested parent settlement under varied delivery and declaration order.
 *
 * A parent waits for its whole child subtree, including retries and recovery. Logs
 * and intermediate barriers prove no descendant or successor runs early. Reversed
 * definitions, randomized delivery choices, and duplicate jobs expose accidental
 * reliance on array order or exactly-once queue delivery.
 */

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Scarlett\Definition\Node;
use Scarlett\Definition\Workflow;
use Scarlett\Engine;
use Scarlett\Execution\Decision;
use Scarlett\Execution\Runner;
use Scarlett\Failures\Runner as FailureRunner;
use Scarlett\Failures\Status;
use Scarlett\States\Completed;
use Scarlett\Tests\Fixtures\ControlledStep;
use Scarlett\Tests\Fixtures\FailureAwareStep;

beforeEach(function () {
    Queue::fake();
    $this->freezeSecond();
    ControlledStep::$actions = [];
    ControlledStep::$constructed = [];
});

it('keeps nested stage barriers for every parallel completion order', function (array $order, bool $reverseDefinition) {
    $nodes = [
        controlled('root'), controlled('left', parent: 'root'), controlled('right', parent: 'root'),
        controlled('a', parent: 'left'), controlled('b', parent: 'left'),
        controlled('left-next', stage: 1, parent: 'left'), controlled('c', parent: 'right'),
        controlled('after', stage: 1),
    ];
    $flow = app(Engine::class)->start(new Workflow($reverseDefinition ? array_reverse($nodes) : $nodes));
    $unrelated = app(Engine::class)->start(new Workflow([controlled('unrelated')]));
    expect(statesOf($flow->id))->toBe([
        'a' => 'pending', 'after' => 'pending', 'b' => 'pending', 'c' => 'pending',
        'left' => 'pending', 'left-next' => 'pending', 'right' => 'pending', 'root' => 'queued',
    ]);
    foreach (['root', 'left', 'right'] as $key) {
        runStep($flow->id, $key);
    }
    $done = [];
    foreach ($order as $key) {
        runStep($flow->id, $key);
        $done[] = $key;
        $states = statesOf($flow->id);
        expect($states['root'])->toBe('waitingforchildren')
            ->and($states['after'])->toBe('pending')
            ->and($states['left'])->toBe('waitingforchildren')
            ->and($states['left-next'])->toBe(count(array_intersect(['a', 'b'], $done)) === 2 ? 'queued' : 'pending')
            ->and($states['right'])->toBe(in_array('c', $done, true) ? 'completed' : 'waitingforchildren');
        foreach (['a', 'b', 'c'] as $leaf) {
            expect($states[$leaf])->toBe(in_array($leaf, $done, true) ? 'completed' : 'queued');
        }
    }
    runStep($flow->id, 'left-next');
    expect(statesOf($flow->id))->toBe([
        'a' => 'completed', 'after' => 'queued', 'b' => 'completed', 'c' => 'completed',
        'left' => 'completed', 'left-next' => 'completed', 'right' => 'completed', 'root' => 'completed',
    ]);
    $after = $flow->steps()->where('key', 'after')->sole();
    expect(DB::table('scarlett_deliveries')->where('step_id', $after->id)->count())->toBe(1);
    runStep($flow->id, 'after');
    expect($flow->fresh()->status)->toBe('completed')
        ->and(ControlledStep::$actions)->toHaveCount(8)
        ->and(array_unique(array_values(ControlledStep::$actions)))->toBe([1])
        ->and(statesOf($unrelated->id))->toBe(['unrelated' => 'queued']);
})->with([
    'abc' => [['a', 'b', 'c']], 'acb' => [['a', 'c', 'b']],
    'bac' => [['b', 'a', 'c']], 'bca' => [['b', 'c', 'a']],
    'cab' => [['c', 'a', 'b']], 'cba' => [['c', 'b', 'a']],
])->with(['forward definition' => false, 'reverse definition' => true]);

it('propagates leaf outcomes through every ancestor without releasing forbidden work', function (int $depth, string $outcome) {
    $nodes = [];
    for ($level = 0; $level < $depth; $level++) {
        $nodes[] = controlled('parent-'.$level, parent: $level === 0 ? null : 'parent-'.($level - 1));
    }
    $nodes[] = controlled('leaf', $outcome === 'cancel' ? 'success' : $outcome, parent: 'parent-'.($depth - 1));
    $nodes[] = controlled('after', stage: 1);
    $nodes[] = controlled('rescue', rescues: 'parent-0');
    $flow = app(Engine::class)->start(new Workflow(array_reverse($nodes)));
    expect(statesOf($flow->id)['leaf'])->toBe('pending');
    for ($level = 0; $level < $depth; $level++) {
        runStep($flow->id, 'parent-'.$level);
        expect(statesOf($flow->id)['after'])->toBe('pending');
    }
    expect(statesOf($flow->id)['leaf'])->toBe('queued');
    if ($outcome === 'cancel') {
        expect(app(Engine::class)->cancel($flow->steps()->where('key', 'leaf')->sole()->id))->toBeTrue();
    } else {
        runStep($flow->id, 'leaf');
    }
    $ancestorState = match ($outcome) {
        'skip' => 'completed', 'cancel' => 'cancelled', default => 'failed',
    };
    for ($level = 0; $level < $depth; $level++) {
        expect(statesOf($flow->id)['parent-'.$level])->toBe($ancestorState);
    }
    expect(statesOf($flow->id)['leaf'])->toBe(match ($outcome) {
        'skip' => 'skipped', 'cancel' => 'cancelled', 'stop' => 'stopped', default => 'failed',
    })->and(statesOf($flow->id)['after'])->toBe($outcome === 'skip' ? 'queued' : 'cancelled')
        ->and(statesOf($flow->id)['rescue'])->toBe(in_array($outcome, ['fail', 'stop'], true) ? 'queued' : 'skipped');
    runStep($flow->id, 'after');
    runStep($flow->id, 'rescue');
    expect($flow->fresh()->status)->toBe(match ($outcome) {
        'skip' => 'completed', 'cancel' => 'cancelled', default => 'failed',
    })->and(ControlledStep::$actions['leaf'] ?? 0)->toBe(0)
        ->and(ControlledStep::$actions['after'] ?? 0)->toBe($outcome === 'skip' ? 1 : 0);
})->with(['one parent' => 1, 'four parents' => 4, 'twelve parents' => 12])
    ->with(['fail', 'stop', 'cancel', 'skip']);

it('never constructs descendants when the root declines execution', function (string $mode) {
    $flow = app(Engine::class)->start(new Workflow([
        controlled('root', $mode), controlled('child', parent: 'root'),
        controlled('grandchild', parent: 'child'), controlled('after', stage: 1),
        controlled('rescue', rescues: 'root'),
    ]));
    expect(ControlledStep::$constructed)->toBe([]);
    runStep($flow->id, 'root');
    runStep($flow->id, 'child');
    runStep($flow->id, 'grandchild');
    expect(ControlledStep::$constructed)->toBe([['root', null]])
        ->and(ControlledStep::$actions)->toBe([])
        ->and(statesOf($flow->id))->toBe([
            'after' => $mode === 'skip' ? 'queued' : 'cancelled',
            'child' => $mode === 'skip' ? 'skipped' : 'cancelled',
            'grandchild' => $mode === 'skip' ? 'skipped' : 'cancelled',
            'rescue' => $mode === 'skip' ? 'skipped' : 'queued',
            'root' => match ($mode) {
                'skip' => 'skipped', 'stop' => 'stopped', default => 'failed'
            },
        ]);
})->with(['skip', 'stop', 'fail']);

it('keeps descendants behind action and verification retry barriers', function (string $mode, string $target) {
    config(['scarlett.retry.attempts' => 2, 'scarlett.retry.backoff_seconds' => [5],
        'scarlett.verification.attempts' => 2, 'scarlett.verification.backoff_seconds' => [5]]);
    $flow = app(Engine::class)->start(new Workflow([
        controlled('root', $target === 'root' ? $mode : 'success'),
        controlled('child', $target === 'child' ? $mode : 'success', parent: 'root'),
        controlled('grandchild', parent: 'child'), controlled('after', stage: 1),
    ]));
    expect(statesOf($flow->id)['grandchild'])->toBe('pending');
    runStep($flow->id, 'root');
    if ($target === 'child') {
        runStep($flow->id, 'child');
    }
    $retrying = $flow->steps()->where('key', $target)->sole();
    expect((string) $retrying->state)->toBe('queued')
        ->and(statesOf($flow->id)['grandchild'])->toBe('pending')
        ->and(statesOf($flow->id)['after'])->toBe('pending');
    runStep($flow->id, $target);
    expect(ControlledStep::$actions[$target])->toBe(1);
    $this->travel(5)->seconds();
    runStep($flow->id, $target);
    expect(statesOf($flow->id))->toBe([
        'after' => 'cancelled', 'child' => $target === 'child' ? 'failed' : 'cancelled',
        'grandchild' => 'cancelled', 'root' => 'failed',
    ])->and(ControlledStep::$actions[$target])->toBe($mode === 'verify' ? 1 : 2)
        ->and(ControlledStep::$actions['grandchild'] ?? 0)->toBe(0)
        ->and($flow->fresh()->status)->toBe('failed');
})->with(['throw', 'verify'])->with(['root', 'child']);

it('holds the parent until a delayed or throttled child actually finishes', function (bool $throttled) {
    $engine = app(Engine::class);
    $flow = $engine->start(new Workflow([
        controlled('root'), new Node('child', ControlledStep::class, inputs: ['name' => 'child'], parent: 'root', notBefore: $throttled ? null : now()->addSeconds(10)),
        controlled('after', stage: 1),
    ]));
    runStep($flow->id, 'root');
    $child = $flow->steps()->where('key', 'child')->sole();
    if ($throttled) {
        expect($engine->claim($child->id, $child->attempt)->id)->toBe($child->id);
        $engine->decide($child->id, $child->attempt, Decision::throttle(10));
    }
    expect(statesOf($flow->id))->toBe(['after' => 'pending', 'child' => 'queued', 'root' => 'waitingforchildren']);
    runStep($flow->id, 'child');
    expect(ControlledStep::$actions)->toBe(['root' => 1]);
    $this->travel(10)->seconds();
    runStep($flow->id, 'child');
    expect(statesOf($flow->id))->toBe(['after' => 'queued', 'child' => 'completed', 'root' => 'completed'])
        ->and($child->fresh()->failures)->toBe(0);
})->with(['scheduled child' => false, 'throttled child' => true]);

it('runs nested rescue work without reopening the failed normal branch', function (string $rescueOutcome) {
    $flow = app(Engine::class)->start(new Workflow([
        controlled('root'), controlled('child', 'fail', parent: 'root'), controlled('after', stage: 1),
        controlled('rescue', rescues: 'root'), controlled('repair', $rescueOutcome, parent: 'rescue'),
        controlled('repair-next', stage: 1, parent: 'rescue'),
    ]));
    expect(statesOf($flow->id)['rescue'])->toBe('pending');
    runStep($flow->id, 'root');
    runStep($flow->id, 'child');
    expect(statesOf($flow->id)['rescue'])->toBe('queued');
    runStep($flow->id, 'rescue');
    expect(statesOf($flow->id)['rescue'])->toBe('waitingforchildren');
    runStep($flow->id, 'repair');
    expect(statesOf($flow->id)['repair-next'])->toBe($rescueOutcome === 'fail' ? 'cancelled' : 'queued');
    runStep($flow->id, 'repair-next');
    runStep($flow->id, 'after');
    expect(statesOf($flow->id)['root'])->toBe('failed')
        ->and(statesOf($flow->id)['after'])->toBe('cancelled')
        ->and(statesOf($flow->id)['rescue'])->toBe($rescueOutcome === 'fail' ? 'failed' : 'completed')
        ->and(ControlledStep::$actions['after'] ?? 0)->toBe(0)
        ->and($flow->fresh()->status)->toBe('failed');
})->with(['success', 'skip', 'fail']);

it('rejects a late grandchild outcome after whole-tree revocation', function () {
    $engine = app(Engine::class);
    $flow = $engine->start(new Workflow([controlled('root'), controlled('child', parent: 'root'), controlled('leaf', parent: 'child'), controlled('after', stage: 1)]));
    runStep($flow->id, 'root');
    runStep($flow->id, 'child');
    $leaf = $flow->steps()->where('key', 'leaf')->sole();
    $engine->claim($leaf->id, $leaf->attempt);
    expect(statesOf($flow->id))->toBe(['after' => 'pending', 'child' => 'waitingforchildren', 'leaf' => 'running', 'root' => 'waitingforchildren']);
    expect($engine->cancelWorkflow($flow->id))->toBeTrue();
    expect($engine->saveAction($leaf->id, $leaf->attempt, 'obsolete', Completed::class))->toBeFalse();
    expect($engine->finish($leaf->id, $leaf->attempt))->toBeFalse();
    expect($engine->whileCurrent($leaf->id, $leaf->attempt, fn () => throw new LogicException('obsolete write')))->toBeFalse();
    expect(statesOf($flow->id))->toBe(['after' => 'cancelled', 'child' => 'cancelled', 'leaf' => 'cancelled', 'root' => 'cancelled'])
        ->and($leaf->fresh()->has_result)->toBeFalse()
        ->and($flow->fresh()->status)->toBe('cancelled');
});

it('drains a branching tree under varied delivery order and duplicate jobs', function (int $seed, bool $failLeaf) {
    $nodes = [controlled('root')];
    $parents = ['root'];
    for ($level = 0; $level < 3; $level++) {
        $next = [];
        foreach ($parents as $parent) {
            for ($branch = 0; $branch < 3; $branch++) {
                $key = $parent.'.'.$branch;
                $nodes[] = controlled($key, $failLeaf && $key === 'root.1.1.1' ? 'fail' : 'success', parent: $parent);
                $next[] = $key;
            }
        }
        $parents = $next;
    }
    $nodes[] = controlled('after', stage: 1);
    usort($nodes, fn (Node $a, Node $b): int => strcmp(hash('sha256', $seed.$a->key), hash('sha256', $seed.$b->key)));
    $flow = app(Engine::class)->start(new Workflow($nodes));
    $unrelated = app(Engine::class)->start(new Workflow([controlled('unrelated')]));
    expect($flow->steps()->where('state', 'queued')->pluck('key')->all())->toBe(['root']);
    $iterations = 0;
    while ($flow->fresh()->finished_at === null && $iterations < count($nodes)) {
        $ready = $flow->steps()->where('state', 'queued')->get()->sortBy(fn ($step): string => hash('sha256', $seed.$step->key));
        expect($ready->isEmpty())->toBeFalse();
        $step = $ready->first();
        app(Runner::class)->run($step->id, $step->attempt);
        $states = statesOf($flow->id);
        $actions = ControlledStep::$actions;
        app(Runner::class)->run($step->id, $step->attempt);
        expect(statesOf($flow->id))->toBe($states)->and(ControlledStep::$actions)->toBe($actions);
        if ($states['after'] === 'queued') {
            expect($states['root'])->toBe('completed');
            foreach ($flow->steps()->whereNotNull('parent_key')->get() as $descendant) {
                expect((string) $descendant->state)->toBe('completed');
            }
        }
        $iterations++;
    }
    expect($flow->fresh()->status)->toBe($failLeaf ? 'failed' : 'completed')
        ->and(statesOf($flow->id)['root'])->toBe($failLeaf ? 'failed' : 'completed')
        ->and(statesOf($flow->id)['after'])->toBe($failLeaf ? 'cancelled' : 'completed')
        ->and($flow->steps()->whereIn('state', ['pending', 'queued', 'running', 'waitingforchildren'])->exists())->toBeFalse()
        ->and(ControlledStep::$actions['after'] ?? 0)->toBe($failLeaf ? 0 : 1)
        ->and(array_unique(array_values(ControlledStep::$actions)))->toBe([1])
        ->and(statesOf($unrelated->id))->toBe(['unrelated' => 'queued']);
    if (! $failLeaf) {
        expect(ControlledStep::$actions)->toHaveCount(count($nodes));
    }
})->with([1, 7, 19, 41])->with(['successful tree' => false, 'failed leaf' => true]);

it('settles durable failure hooks from the deepest descendant upward even after a hook retry', function () {
    FailureAwareStep::$hookCalls = 0;
    FailureAwareStep::$hookThrows = false;
    FailureAwareStep::$constructionThrows = false;
    config(['scarlett.retry.backoff_seconds' => [3]]);
    $nodes = [];
    $keys = ['root', 'child', 'grandchild', 'leaf'];
    foreach ($keys as $index => $key) {
        $nodes[] = new Node($key, FailureAwareStep::class,
            inputs: ['name' => $key, 'mode' => $key === 'leaf' ? 'fail' : 'success'],
            parent: $index === 0 ? null : $keys[$index - 1]);
    }
    $nodes[] = controlled('after', stage: 1);
    $flow = app(Engine::class)->start(new Workflow($nodes));
    expect($flow->failures()->exists())->toBeFalse();
    foreach ($keys as $key) {
        runStep($flow->id, $key);
    }
    expect(statesOf($flow->id))->toBe([
        'after' => 'cancelled', 'child' => 'failed', 'grandchild' => 'failed', 'leaf' => 'failed', 'root' => 'failed',
    ])->and($flow->fresh()->status)->toBe('running');
    $runner = app(FailureRunner::class);
    $steps = $flow->steps()->get()->keyBy('key');
    foreach (['root', 'child', 'grandchild'] as $key) {
        $failure = $flow->failures()->where('step_id', $steps[$key]->id)->sole();
        expect($failure->status)->toBe(Status::Pending);
        $runner->run($failure->id, $failure->attempt ?? 'unclaimed');
    }
    expect(FailureAwareStep::$hookCalls)->toBe(0);
    $leafFailure = $flow->failures()->where('step_id', $steps['leaf']->id)->sole();
    FailureAwareStep::$hookThrows = true;
    $runner->run($leafFailure->id, $leafFailure->attempt);
    FailureAwareStep::$hookThrows = false;
    expect($leafFailure->fresh()->failures)->toBe(1)
        ->and($flow->failures()->where('step_id', $steps['grandchild']->id)->sole()->status)->toBe(Status::Pending);
    $this->travel(3)->seconds();
    $handled = [];
    foreach (array_reverse($keys) as $key) {
        $failure = $flow->failures()->where('step_id', $steps[$key]->id)->sole();
        expect($failure->status)->toBe(Status::Queued);
        $runner->run($failure->id, $failure->attempt);
        $runner->run($failure->id, $failure->attempt);
        $handled[] = $key;
        expect(array_column($flow->fresh()->metadata['failures'], 'name'))->toBe($handled)
            ->and($failure->fresh()->status)->toBe(Status::Completed);
    }
    expect($flow->fresh()->status)->toBe('failed')
        ->and(FailureAwareStep::$hookCalls)->toBe(5)
        ->and(array_column($flow->fresh()->metadata['failures'], 'cause'))->toBe(['decided', 'child_failed', 'child_failed', 'child_failed']);
});
