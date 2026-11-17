<?php

declare(strict_types=1);

/**
 * Checks replacing a subject's current workflow without losing atomic ownership.
 *
 * The previous attempt is revoked before preparation, but a veto must roll that
 * revocation back along with pointer and application changes. Separate subjects and
 * slot names expose unintended replacement scope. Outer transaction cases prove
 * queue jobs are withheld until the real commit, not just the inner callback return.
 */

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Scarlett\Definition\SubjectSlot;
use Scarlett\Definition\Workflow;
use Scarlett\Engine;
use Scarlett\History;
use Scarlett\Jobs\ExecuteStep;
use Scarlett\Models\WorkflowRecord;

beforeEach(function () {
    Queue::fake();
});

it('replaces only a named current slot and revokes the old attempt before preparing the replacement', function () {
    $engine = app(Engine::class);
    $slot = new SubjectSlot('document', '0', 'current');
    expect($engine->current($slot))->toBeNull();
    $old = $engine->replace($slot, fn () => new Workflow([controlled('old')]));
    $mail = $engine->replace(new SubjectSlot('document', '0', 'mail'), fn () => new Workflow([controlled('mail')]));
    $step = $old->steps()->sole();
    $engine->claim($step->id, $step->attempt);
    $next = $engine->replace($slot, function (?WorkflowRecord $previous) use ($engine, $step, $old) {
        expect($previous->id)->toBe($old->id);
        expect($engine->whileCurrent($step->id, $step->attempt, fn () => throw new LogicException('Obsolete write')))->toBeFalse();

        return new Workflow([controlled('new')], metadata: ['revision' => 2]);
    });
    expect($engine->current($slot))->toBe($next->id)
        ->and($next->subject_type)->toBe('document')->and($next->subject_id)->toBe('0')
        ->and($old->fresh()->status)->toBe('cancelled')->and($mail->fresh()->status)->toBe('running');
});

it('rolls back cancellation pointer and callback writes when replacement is vetoed', function (mixed $veto) {
    $engine = app(Engine::class);
    $slot = new SubjectSlot('document', '42', 'current');
    $old = $engine->replace($slot, fn () => new Workflow([controlled('old')]));
    $step = $old->steps()->sole();
    $engine->claim($step->id, $step->attempt);
    $history = DB::table('scarlett_history')->where('step_id', $step->id)->count();
    $result = $engine->replace($slot, function ($previous) use ($veto) {
        $previous->update(['metadata' => ['must_rollback' => true]]);

        return $veto;
    });
    expect($result)->toBeNull()->and($engine->current($slot))->toBe($old->id)
        ->and($engine->isCurrent($step->id, $step->attempt))->toBeTrue()
        ->and($old->fresh()->metadata)->toBe([])
        ->and(DB::table('scarlett_history')->where('step_id', $step->id)->count())->toBe($history);
})->with([null, false]);

it('rolls back a failed definition or callback and never publishes replacement jobs', function (bool $invalid) {
    $engine = app(Engine::class);
    $slot = new SubjectSlot('document', '42', 'current');
    $old = $engine->replace($slot, fn () => new Workflow([controlled('old')]));
    Queue::fake();
    expect(fn () => $engine->replace($slot, function () use ($invalid) {
        if ($invalid) {
            return new Workflow([]);
        }
        throw new RuntimeException('Consumer refused');
    }))->toThrow($invalid ? InvalidArgumentException::class : RuntimeException::class);
    expect($engine->current($slot))->toBe($old->id)->and($old->fresh()->status)->toBe('running');
    Queue::assertNothingPushed();
})->with([true, false]);

it('keeps slot identities exact and handles held completed and deleted current workflows', function () {
    $engine = app(Engine::class);
    $slot = new SubjectSlot('Document', '42', 'current');
    $engine->cooldown();
    $flow = $engine->replace($slot, fn () => new Workflow([controlled('held')]));
    expect($flow->status)->toBe('held')->and($engine->current(new SubjectSlot('document', '42', 'current')))->toBeNull();
    $engine->reopen();
    runStep($flow->id, 'held');
    expect($engine->current($slot))->toBe($flow->id);
    expect(app(History::class)->delete($flow->id))->toBeTrue();
    expect($engine->current($slot))->toBeNull();
    $next = $engine->replace($slot, fn () => new Workflow([controlled('next')]));
    expect($engine->current($slot))->toBe($next->id);
});

it('defers publication to the outer commit and restores replacement on outer rollback', function () {
    $engine = app(Engine::class);
    $slot = new SubjectSlot('document', 'outer-transaction', 'current');
    $old = $engine->replace($slot, fn () => new Workflow([controlled('old')]));
    Queue::fake();
    DB::beginTransaction();
    try {
        $replacement = $engine->replace($slot, fn () => new Workflow([controlled('new')]));
        expect($engine->current($slot))->toBe($replacement->id);
        Queue::assertNothingPushed();
    } finally {
        DB::rollBack();
    }
    expect($engine->current($slot))->toBe($old->id)->and($old->fresh()->status)->toBe('running');
    Queue::assertNothingPushed();
    DB::transaction(function () use ($engine, $slot) {
        $engine->replace($slot, fn () => new Workflow([controlled('committed')]));
        Queue::assertNothingPushed();
    });
    Queue::assertPushed(ExecuteStep::class, 1);
});
