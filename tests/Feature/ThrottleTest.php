<?php

declare(strict_types=1);

/**
 * Compares equivalent throttle waits requested through different lifecycle paths.
 *
 * A policy, precheck, Throttled exception, and exception handler must all preserve
 * the action failure budget. Frozen time proves zero waits receive the configured
 * minimum while explicit positive waits and ordinary zero-delay retries retain their
 * own meaning. Action counters expose any execution that occurs while blocked.
 */

use Illuminate\Support\Facades\Queue;
use Scarlett\Definition\Node;
use Scarlett\Definition\Workflow;
use Scarlett\Engine;
use Scarlett\Execution\Decision;
use Scarlett\Tests\Fixtures\ThrottlingStep;

beforeEach(function () {
    Queue::fake();
    $this->freezeSecond();
    ThrottlingStep::$blocked = true;
    ThrottlingStep::$actions = 0;
});

it('waits the configured minimum for zero throttles without spending failures', function (string $source) {
    config(['scarlett.minimum_throttle_seconds' => 7]);
    $flow = app(Engine::class)->start(new Workflow([new Node('limited', ThrottlingStep::class, inputs: ['source' => $source])]));
    expect(statesOf($flow->id))->toBe(['limited' => 'queued']);
    runStep($flow->id, 'limited');
    $step = $flow->steps()->sole();
    expect((string) $step->state)->toBe('queued')->and($step->failures)->toBe(0)
        ->and($step->verifications)->toBe(0)->and($step->available_at->equalTo(now()->addSeconds(7)))->toBeTrue();
    ThrottlingStep::$blocked = false;
    runStep($flow->id, 'limited');
    expect(ThrottlingStep::$actions)->toBe(0);
    $this->travel(7)->seconds();
    runStep($flow->id, 'limited');
    expect($flow->fresh()->status)->toBe('completed')->and(ThrottlingStep::$actions)->toBe(1)
        ->and($step->fresh()->failures)->toBe(0);
})->with(['policy', 'precheck', 'exception', 'handler']);

it('normalizes direct zero throttle outcomes but preserves ordinary zero retries and positive waits', function () {
    config(['scarlett.minimum_throttle_seconds' => 7]);
    $engine = app(Engine::class);
    $flow = $engine->start(new Workflow([controlled('throttle'), controlled('retry'), controlled('positive')]));
    foreach (['throttle' => Decision::throttle(0), 'retry' => Decision::retry(0), 'positive' => Decision::throttle(2)] as $key => $decision) {
        $step = $flow->steps()->where('key', $key)->sole();
        $engine->claim($step->id, $step->attempt);
        $engine->decide($step->id, $step->attempt, $decision);
    }
    $steps = $flow->steps()->get()->keyBy('key');
    expect($steps['throttle']->available_at->equalTo(now()->addSeconds(7)))->toBeTrue()
        ->and($steps['throttle']->failures)->toBe(0);
    expect($steps['retry']->available_at->equalTo(now()))->toBeTrue()->and($steps['retry']->failures)->toBe(1);
    expect($steps['positive']->available_at->equalTo(now()->addSeconds(2)))->toBeTrue()->and($steps['positive']->failures)->toBe(0);
});

it('supplies a positive default zero-throttle wait', function () {
    expect(config('scarlett.minimum_throttle_seconds'))->toBe(1);
});

it('rejects invalid minimum throttle waits before publication', function (mixed $seconds) {
    config(['scarlett.minimum_throttle_seconds' => $seconds]);
    expect(fn () => app(Engine::class)->start(new Workflow([controlled('invalid')])))->toThrow(InvalidArgumentException::class, 'minimum_throttle_seconds');
    Queue::assertNothingPushed();
})->with([0, -1, null, '1', 1.5]);
