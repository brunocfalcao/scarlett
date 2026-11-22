<?php

declare(strict_types=1);

/**
 * Checks deletion of every workflow lane belonging to one exact business subject.
 *
 * Guard subjects are supplied for the callback to inspect, but must remain unchanged.
 * Inspect dependent history and attempt authority after approval; inspect callback
 * writes and original authority after veto, exception, or outer rollback. This proves
 * the operation is atomic rather than merely returning the expected boolean.
 */

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Scarlett\Definition\Node;
use Scarlett\Definition\Subject;
use Scarlett\Definition\SubjectSlot;
use Scarlett\Definition\Workflow;
use Scarlett\Engine;
use Scarlett\Execution\Context;
use Scarlett\Execution\Decision;
use Scarlett\Failures\Coordinator;
use Scarlett\History;
use Scarlett\Models\WorkflowRecord;
use Scarlett\Tests\Fixtures\FailureAwareStep;

beforeEach(function () {
    Queue::fake();
});

it('atomically deletes every target lane and history while leaving guarded subjects unchanged', function () {
    $engine = app(Engine::class);
    $slot = new SubjectSlot('document', 'delete-me', 'current');
    $active = $engine->replace($slot, fn () => new Workflow([controlled('active')]));
    $step = $active->steps()->sole();
    $engine->claim($step->id, $step->attempt);
    $mail = $engine->start(new Workflow([controlled('mail')], 'document', 'delete-me'));
    $done = $engine->start(new Workflow([controlled('done')], 'document', 'delete-me'));
    runStep($done->id, 'done');
    app(History::class)->archive($done->id);
    $failed = $engine->start(new Workflow([
        new Node('failure', FailureAwareStep::class, inputs: ['name' => 'failure', 'mode' => 'fail']),
    ], 'document', 'delete-me'));
    runStep($failed->id, 'failure');
    $failure = $failed->failures()->sole();
    app(Coordinator::class)->claim($failure->id, $failure->attempt);
    $failureContext = new Context($failed->id, $failure->step_id, $failure->attempt, 0, failureId: $failure->id);
    $guard = $engine->start(new Workflow([controlled('guard')], 'document', 'keep-me'));
    $engine->cooldown();
    $held = $engine->start(new Workflow([controlled('held')], 'document', 'delete-me'));
    $ids = [$active->id, $mail->id, $done->id, $failed->id, $held->id];
    $stepIds = WorkflowRecord::whereKey($ids)->get()->flatMap(fn ($flow) => $flow->steps()->pluck('id'))->all();
    expect($engine->deleteSubject(new Subject('document', 'delete-me'), function (Collection $locked) use ($step, $engine, $guard) {
        expect($engine->isCurrent($step->id, $step->attempt))->toBeTrue();
        expect($locked->modelKeys())->toContain($guard->id);
        expect($locked->every(fn ($flow) => $flow->relationLoaded('steps')))->toBeTrue();

        return true;
    }, guardSubjects: [new Subject('document', 'keep-me')]))->toBeTrue();
    expect(WorkflowRecord::whereKey($ids)->count())->toBe(0)
        ->and($guard->fresh()->status)->toBe('running')
        ->and($engine->current($slot))->toBeNull()
        ->and(DB::table('scarlett_subject_slots')->where('id', $slot->id())->exists())->toBeFalse();
    foreach (['scarlett_history', 'scarlett_deliveries', 'scarlett_failures'] as $table) {
        expect(DB::table($table)->whereIn('step_id', $stepIds)->count())->toBe(0);
    }
    expect($engine->claim($step->id, $step->attempt))->toBeNull()
        ->and($engine->decide($step->id, $step->attempt, Decision::fail()))->toBeFalse()
        ->and($engine->whileCurrent($step->id, $step->attempt, fn () => throw new LogicException('Stale write')))->toBeFalse()
        ->and($failureContext->whileCurrent(fn () => throw new LogicException('Stale hook')))->toBeFalse();
});

it('rolls back callback writes on veto or exception before revoking anything', function (string $outcome) {
    $engine = app(Engine::class);
    $slot = new SubjectSlot('document', 'veto-me', 'current');
    $flow = $engine->replace($slot, fn () => new Workflow([controlled('active')]));
    $step = $flow->steps()->sole();
    $engine->claim($step->id, $step->attempt);
    $operation = fn () => $engine->deleteSubject(new Subject('document', 'veto-me'), function (Collection $locked) use ($outcome) {
        $locked->sole()->update(['metadata' => ['must_rollback' => true]]);

        return match ($outcome) {
            'exception' => throw new RuntimeException('Consumer veto'),
            'invalid' => 'yes',
            'null' => null,
            default => false,
        };
    });
    if (in_array($outcome, ['exception', 'invalid'], true)) {
        expect($operation)->toThrow($outcome === 'invalid' ? InvalidArgumentException::class : RuntimeException::class);
    } else {
        expect($operation())->toBeFalse();
    }
    expect($engine->isCurrent($step->id, $step->attempt))->toBeTrue()
        ->and($engine->current($slot))->toBe($flow->id)
        ->and($flow->fresh()->metadata)->toBe([]);
})->with(['false', 'null', 'exception', 'invalid']);

it('can veto from a related subjects running step without revoking that subject', function () {
    $engine = app(Engine::class);
    $target = $engine->start(new Workflow([controlled('target')], 'document', 'target'));
    $guard = $engine->start(new Workflow([controlled('send')], 'document', 'guard'));
    $step = $guard->steps()->sole();
    $engine->claim($step->id, $step->attempt);
    $deleted = $engine->deleteSubject(new Subject('document', 'target'), fn (Collection $locked) => ! $locked->contains(
        fn ($flow) => $flow->steps->contains(fn ($step) => (string) $step->state === 'running'),
    ), guardSubjects: [new Subject('document', 'guard')]);
    expect($deleted)->toBeFalse()->and($target->fresh()->status)->toBe('running')
        ->and($engine->isCurrent($step->id, $step->attempt))->toBeTrue();
});

it('runs the callback for empty subjects and restores deletion when an outer transaction rolls back', function () {
    $engine = app(Engine::class);
    expect($engine->deleteSubject(new Subject('document', 'empty'), function (Collection $locked) {
        expect($locked)->toHaveCount(0);

        return true;
    }))->toBeTrue();
    $slot = new SubjectSlot('document', 'outer', 'current');
    $flow = $engine->replace($slot, fn () => new Workflow([controlled('target')]));
    DB::beginTransaction();
    try {
        expect($engine->deleteSubject(new Subject('document', 'outer'), fn () => true))->toBeTrue();
        expect($engine->current($slot))->toBeNull();
    } finally {
        DB::rollBack();
    }
    expect($engine->current($slot))->toBe($flow->id)->and($flow->fresh()->status)->toBe('running');
});

it('locks every retained workflow step set in one batched read before the consumer callback runs', function () {
    $engine = app(Engine::class);
    $flows = collect(range(1, 4))->map(fn (int $index) => $engine->start(new Workflow([controlled("batch-{$index}")], 'document', 'batch-delete')));
    $statements = [];
    DB::listen(function (QueryExecuted $query) use (&$statements): void {
        $statements[] = str_replace('`', '"', $query->sql);
    });
    $stepReadsBeforeCallback = null;
    expect($engine->deleteSubject(new Subject('document', 'batch-delete'), function (Collection $locked) use (&$stepReadsBeforeCallback, &$statements): bool {
        $stepReadsBeforeCallback = collect($statements)->filter(fn (string $sql): bool => str_contains($sql, 'from "scarlett_steps"'))->count();
        expect($locked->count())->toBe(4)
            ->and($locked->every(fn ($flow): bool => $flow->relationLoaded('steps') && $flow->steps->count() === 1))->toBeTrue();

        return true;
    }))->toBeTrue();
    $stepRead = collect($statements)->first(fn (string $sql): bool => str_contains($sql, 'from "scarlett_steps"'));
    expect($stepReadsBeforeCallback)->toBe(1)
        ->and($stepRead)->toContain('order by "workflow_id" asc, "id" asc')
        ->and(WorkflowRecord::whereKey($flows->pluck('id')->all())->count())->toBe(0);
});

it('does not issue a slot delete when the subject has no slot pointers', function () {
    $statements = [];
    DB::listen(function (QueryExecuted $query) use (&$statements): void {
        $statements[] = str_replace('`', '"', $query->sql);
    });
    expect(app(Engine::class)->deleteSubject(new Subject('document', 'nothing-here'), fn (): bool => true))->toBeTrue();
    expect(collect($statements)->filter(fn (string $sql): bool => str_starts_with($sql, 'delete from "scarlett_subject_slots"'))->count())->toBe(0);
});

it('reads step locks in bounded batches of workflows, keeping one lock order across batches', function () {
    $engine = app(Engine::class);
    $flows = collect(range(1, 501))->map(fn (int $index) => $engine->start(new Workflow([controlled("bulk-{$index}")], 'document', 'bulk-delete')));
    $statements = [];
    DB::listen(function (QueryExecuted $query) use (&$statements): void {
        $statements[] = str_replace('`', '"', $query->sql);
    });
    $stepReadsBeforeCallback = null;
    expect($engine->deleteSubject(new Subject('document', 'bulk-delete'), function (Collection $locked) use (&$stepReadsBeforeCallback, &$statements): bool {
        $stepReadsBeforeCallback = collect($statements)->filter(fn (string $sql): bool => str_contains($sql, 'from "scarlett_steps"'))->count();
        expect($locked->count())->toBe(501)
            ->and($locked->every(fn ($flow): bool => $flow->relationLoaded('steps') && $flow->steps->count() === 1))->toBeTrue();

        return true;
    }))->toBeTrue();
    expect($stepReadsBeforeCallback)->toBe(2)
        ->and(WorkflowRecord::whereKey($flows->pluck('id')->all())->count())->toBe(0);
});
