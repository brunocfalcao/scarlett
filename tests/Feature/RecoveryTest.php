<?php

declare(strict_types=1);

/**
 * Checks repair after interrupted execution or queue publication.
 *
 * Manipulate delivery timestamps and leases to model lost work, then ask recovery
 * to act. Token comparisons prove the old worker cannot save afterward. Persisted
 * action checkpoints prove a repaired verification path does not repeat handle().
 * Publication faults occur after commit and must leave recoverable intent, not erase work.
 */

use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Scarlett\Admission;
use Scarlett\Definition\Workflow;
use Scarlett\Engine;
use Scarlett\Execution\Decision;
use Scarlett\Execution\Delivery;
use Scarlett\Inputs\PayloadCodec;
use Scarlett\Jobs\ExecuteStep;
use Scarlett\Models\StepRecord;
use Scarlett\Recovery;
use Scarlett\States\Completed;
use Scarlett\Tests\Fixtures\ControlledStep;

beforeEach(function () {
    Queue::fake();
    $this->freezeSecond();
});
it('holds new workflows during cooldown but drains started workflows', function () {
    $first = app(Engine::class)->start(new Workflow([controlled('a'), controlled('b', stage: 1)]));
    app(Admission::class)->cooldown();
    $held = app(Engine::class)->start(new Workflow([controlled('held')]));
    expect($held->status)->toBe('held')->and(statesOf($held->id))->toBe(['held' => 'pending']);
    runStep($first->id, 'a');
    expect(statesOf($first->id))->toBe(['a' => 'completed', 'b' => 'queued']);
    app(Admission::class)->reopen();
    expect(statesOf($held->id))->toBe(['held' => 'queued']);
});

it('registers one delivery callback when reopening multiple held workflows', function () {
    $engine = app(Engine::class);
    $engine->cooldown();
    $first = $engine->start(new Workflow([controlled('held-first')]));
    $second = $engine->start(new Workflow([controlled('held-second')]));

    DB::beginTransaction();
    $engine->reopen();

    $callbacks = app('db.transactions')->getCommittedTransactions()->flatMap(
        fn ($transaction) => $transaction->getCallbacks(),
    );
    expect($callbacks)->toHaveCount(1);

    DB::commit();
    expect(statesOf($first->id))->toBe(['held-first' => 'queued'])
        ->and(statesOf($second->id))->toBe(['held-second' => 'queued']);
    Queue::assertPushed(ExecuteStep::class, 2);
});
it('recovers expired ownership and rejects the old worker outcome', function () {
    config(['scarlett.lease_seconds' => 20, 'scarlett.worker_timeout' => 10]);
    $engine = app(Engine::class);
    $flow = $engine->start(new Workflow([controlled('a')]));
    $step = $flow->steps()->sole();
    $engine->claim($step->id, $step->attempt);
    expect(app(Recovery::class)->stalled($flow->id))->toBeFalse();
    $this->travel(21)->seconds();
    expect(app(Recovery::class)->stalled($flow->id))->toBeTrue();
    expect(app(Recovery::class)->resume($flow->id))->toBe(1);
    expect((string) $step->fresh()->state)->toBe('queued');
    expect($step->fresh()->attempt)->not->toBe($step->attempt);
    expect($engine->decide($step->id, $step->attempt, Decision::fail()))->toBeFalse();
});
it('replays an unsent delivery without claiming a healthy running step', function () {
    $flow = app(Engine::class)->start(new Workflow([controlled('a'), controlled('b')]));
    $running = $flow->steps()->where('key', 'a')->sole();
    app(Engine::class)->claim($running->id, $running->attempt);
    $queued = $flow->steps()->where('key', 'b')->sole();
    DB::table('scarlett_deliveries')->where('step_id', $queued->id)->update(['sent_at' => null]);
    expect(app(Recovery::class)->stalled($flow->id))->toBeTrue();
    expect(app(Recovery::class)->resume($flow->id))->toBe(1);
    expect($running->fresh()->attempt)->toBe($running->attempt);
    expect(statesOf($flow->id))->toBe(['a' => 'running', 'b' => 'queued']);
    expect(DB::table('scarlett_deliveries')->where('step_id', $queued->id)->value('sent_at'))->toBe(now()->toDateTimeString());
});

it('does not replay a queued step without an eligible delivery', function (string $condition) {
    $flow = app(Engine::class)->start(new Workflow([controlled('queued')]));
    $step = $flow->steps()->sole();
    $delivery = DB::table('scarlett_deliveries')->where('step_id', $step->id);
    if ($condition === 'missing') {
        $delivery->delete();
    } elseif ($condition === 'publishing') {
        $delivery->update(['sent_at' => null, 'publishing_until' => now()->addSeconds(config('scarlett.delivery_retry_seconds'))]);
    }
    $before = $delivery->first();
    Queue::fake();
    expect(app(Engine::class)->recover($step->id))->toBeFalse()
        ->and($delivery->first())->toEqual($before)
        ->and($step->fresh()->attempt)->toBe($step->attempt)
        ->and((string) $step->fresh()->state)->toBe('queued');
    Queue::assertNothingPushed();
})->with(['missing', 'publishing', 'recently sent']);
it('resumes confirmation after a crash without repeating the saved action', function () {
    $engine = app(Engine::class);
    ControlledStep::$actions = [];
    $flow = $engine->start(new Workflow([controlled('a')]));
    $step = $flow->steps()->sole();
    $engine->claim($step->id, $step->attempt);
    $engine->saveAction($step->id, $step->attempt, false, Completed::class);
    $step->fresh()->update(['lease_until' => now()->subSecond()]);
    expect($engine->resume($flow->id))->toBe(1);
    expect($step->fresh()->action_completed)->toBeTrue();
    $this->travel(5)->seconds();
    runStep($flow->id, 'a');
    expect((string) $step->fresh()->state)->toBe('completed');
    expect(ControlledStep::$actions)->toBe([]);
    expect(app(PayloadCodec::class)->decode($step->fresh()->result))->toBeFalse();
});
it('keeps the next delivery durable when transport fails after an outcome commit', function () {
    $engine = app(Engine::class);
    $flow = $engine->start(new Workflow([controlled('a'), controlled('b', stage: 1)]));
    Bus::shouldReceive('dispatch')->andThrow(new RuntimeException('Transport unavailable'));
    runStep($flow->id, 'a');
    $next = $flow->steps()->where('key', 'b')->sole();
    expect(statesOf($flow->id))->toBe(['a' => 'completed', 'b' => 'queued']);
    expect(DB::table('scarlett_deliveries')->where('step_id', $next->id)->value('sent_at'))->toBeNull();
    expect($engine->stalled($flow->id))->toBeTrue();
});
it('does not publish the same delivery twice during overlapping flushes', function () {
    $flow = app(Engine::class)->start(new Workflow([controlled('a')]));
    DB::table('scarlett_deliveries')->where('step_id', $flow->steps()->sole()->id)->update(['sent_at' => null]);
    $dispatches = 0;
    Bus::shouldReceive('dispatch')->andReturnUsing(function () use (&$dispatches, $flow): void {
        $dispatches++;
        if ($dispatches === 1) {
            app(Delivery::class)->flush($flow->id);
        }
    });
    app(Delivery::class)->flush($flow->id);
    expect($dispatches)->toBe(1);
});

it('returns committed work when the publication lookup fails', function () {
    $fail = true;
    StepRecord::retrieved(function () use (&$fail): void {
        if ($fail && DB::transactionLevel() === 0) {
            throw new RuntimeException('Publication lookup unavailable.');
        }
    });
    $flow = app(Engine::class)->start(new Workflow([controlled('durable')]));
    $fail = false;
    $step = $flow->steps()->sole();
    expect($flow->fresh()->status)->toBe('running')->and((string) $step->state)->toBe('queued');
    expect(DB::table('scarlett_deliveries')->where('step_id', $step->id)->value('sent_at'))->toBeNull();
    expect(app(Engine::class)->resume($flow->id))->toBe(1);
    runStep($flow->id, 'durable');
    expect($flow->fresh()->status)->toBe('completed');
});
