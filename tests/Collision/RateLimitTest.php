<?php

declare(strict_types=1);

/**
 * Races shared quota reservations across different workflow locks.
 *
 * The throttle row is the shared contention point. Two workers asking for the same
 * single allowance must yield one grant and one wait, while each attempt's duplicate
 * check stays free. The snapshot case proves a stale outer transaction cannot hide
 * a receipt already committed by another connection.
 */

use Illuminate\Support\Facades\DB;
use Scarlett\Definition\Workflow;
use Scarlett\Engine;
use Scarlett\Execution\Context;
use Scarlett\Policies\RateLimit;
use Scarlett\Tests\Collision\Environment;
use Scarlett\Tests\Collision\Workers;

it('grants one shared allowance to simultaneous attempts in different workflows', function () {
    $this->freezeSecond();
    config(['scarlett.throttles' => ['shared' => ['max_requests' => 1, 'per_seconds' => 60]]]);
    $key = 'race-'.bin2hex(random_bytes(8));
    $policy = new RateLimit('shared', $key);
    DB::table('scarlett_throttles')->insert([
        'id' => $policy->id(), 'grants' => '[]', 'expires_at' => 0, 'max_requests' => 1, 'per_seconds' => 60,
    ]);
    $operations = [];
    $steps = [];
    foreach (['first', 'second'] as $name) {
        $workflow = app(Engine::class)->start(new Workflow([controlled($name)]));
        $step = $workflow->steps()->sole();
        app(Engine::class)->claim($step->id, $step->attempt);
        $steps[] = $step;
        $operations[] = ['operation' => 'rate-limit', 'step' => $step->id, 'attempt' => $step->attempt,
            'workflow' => $workflow->id, 'key' => $key, 'now' => now()->toIso8601String()];
    }
    expect(json_decode(DB::table('scarlett_throttles')->where('id', $policy->id())->value('grants'), true))->toBe([]);
    $results = array_column(Workers::race($policy->id(), $operations, 'scarlett_throttles'), 'result');
    expect($results)->toEqualCanonicalizing([[null, null], [60, 60]])
        ->and(json_decode(DB::table('scarlett_throttles')->where('id', $policy->id())->value('grants'), true))->toBe([now()->getTimestampMs()]);
    foreach ($steps as $index => $step) {
        $receipt = $results[$index][0] === null ? ['attempt' => $step->attempt, 'limits' => [$policy->id()]] : null;
        $actual = $step->fresh()->throttle_reservations;
        if ($actual !== null) {
            ksort($actual);
        }
        if ($receipt !== null) {
            ksort($receipt);
        }
        expect($actual)->toBe($receipt)->and($step->fresh()->failures)->toBe(0);
    }
});

it('reads the latest receipt even inside an older consumer transaction snapshot', function () {
    $this->freezeSecond();
    config(['scarlett.throttles' => ['shared' => ['max_requests' => 1, 'per_seconds' => 60]]]);
    $flow = app(Engine::class)->start(new Workflow([controlled('snapshot')]));
    $step = $flow->steps()->sole();
    app(Engine::class)->claim($step->id, $step->attempt);
    $context = new Context($flow->id, $step->id, $step->attempt, 0);
    $policy = new RateLimit('shared', 'snapshot');
    $driver = Environment::driver();
    config(['database.connections.concurrent' => config('database.connections.'.$driver)]);
    DB::beginTransaction();
    try {
        expect($step->fresh()->throttle_reservations)->toBeNull();
        Environment::concurrentCommit(fn () => expect($policy->delay($context))->toBeNull());
        expect($policy->delay($context))->toBeNull();
        DB::commit();
        expect(json_decode(DB::table('scarlett_throttles')->where('id', $policy->id())->value('grants'), true))->toBe([now()->getTimestampMs()]);
    } finally {
        DB::setDefaultConnection($driver);
        while (DB::transactionLevel() > 0) {
            DB::rollBack();
        }
        DB::purge('concurrent');
    }
});
