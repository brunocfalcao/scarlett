<?php

declare(strict_types=1);

/**
 * Checks rolling-window capacity and per-attempt receipts at controlled times.
 *
 * Policies share a quota only when both name and key match. Claim action contexts
 * explicitly, freeze time, and inspect saved grants so tests can prove exact boundaries,
 * rounded-up waits, stale-worker rejection, and rollback. Action retries spend failures;
 * waiting for quota does not. Migration and pruning cases preserve active windows.
 */

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Scarlett\Definition\Node;
use Scarlett\Definition\Workflow;
use Scarlett\Engine;
use Scarlett\Execution\Context;
use Scarlett\Execution\Decision;
use Scarlett\Execution\ThrottleReservations;
use Scarlett\Models\StepRecord;
use Scarlett\Models\WorkflowRecord;
use Scarlett\Policies\RateLimit;
use Scarlett\Tests\Fixtures\RateLimitedStep;

beforeEach(function () {
    Queue::fake();
    $this->freezeSecond();
    RateLimitedStep::$calls = [];
    config(['scarlett.throttles' => [
        'slow' => ['max_requests' => 1, 'per_seconds' => 60],
        'fast' => ['max_requests' => 1, 'per_seconds' => 10],
        'bulk' => ['max_requests' => 20, 'per_seconds' => 60],
    ]]);
});

function limitedWorkflow(string $policy = 'slow', string $account = 'account', bool $fail = false): WorkflowRecord
{
    return app(Engine::class)->start(new Workflow([
        new Node('call', RateLimitedStep::class, inputs: compact('policy', 'account', 'fail')),
    ]));
}

function runningLimitContext(WorkflowRecord $flow): Context
{
    $step = $flow->steps()->sole();
    app(Engine::class)->claim($step->id, $step->attempt);

    return new Context($flow->id, $step->id, $step->attempt, $step->failures);
}

it('shares an allowance across workflows while isolating five different keys', function () {
    $flows = [];
    foreach (['one', 'two', 'three', 'four', 'five'] as $account) {
        $flows[] = limitedWorkflow(account: $account);
    }
    foreach ($flows as $flow) {
        expect(statesOf($flow->id))->toBe(['call' => 'queued']);
        runStep($flow->id, 'call');
        expect($flow->fresh()->status)->toBe('completed');
    }
    $blocked = limitedWorkflow(account: 'one');
    runStep($blocked->id, 'call');
    $step = $blocked->steps()->sole();
    expect($step->available_at->equalTo(now()->addSeconds(60)))->toBeTrue()
        ->and((string) $step->state)->toBe('queued')->and($step->failures)->toBe(0)
        ->and(RateLimitedStep::$calls)->toBe(array_map(fn ($flow) => $flow->steps()->sole()->id, $flows));
});

it('runs independent sixty and ten second policies without repeatedly deferring admitted work', function () {
    $flows = [];
    foreach (['slow', 'fast'] as $policy) {
        $first = limitedWorkflow($policy);
        runStep($first->id, 'call');
        $flows[$policy] = limitedWorkflow($policy);
        runStep($flows[$policy]->id, 'call');
    }
    expect(RateLimitedStep::$calls)->toHaveCount(2);
    $this->travel(10)->seconds();
    runStep($flows['fast']->id, 'call');
    runStep($flows['slow']->id, 'call');
    expect($flows['fast']->fresh()->status)->toBe('completed')
        ->and(statesOf($flows['slow']->id))->toBe(['call' => 'queued']);
    $this->travel(50)->seconds();
    runStep($flows['slow']->id, 'call');
    expect($flows['slow']->fresh()->status)->toBe('completed')->and(RateLimitedStep::$calls)->toHaveCount(4);
});

it('allows twenty admissions in a rolling minute and releases only expired capacity', function () {
    $policy = new RateLimit('bulk', 'provider');
    $first = runningLimitContext(limitedWorkflow('bulk', 'provider'));
    expect($policy->delay($first))->toBeNull();
    $this->travel(10)->seconds();
    for ($index = 1; $index < 20; $index++) {
        expect($policy->delay(runningLimitContext(limitedWorkflow('bulk', 'provider'))))->toBeNull();
    }
    $waiting = runningLimitContext(limitedWorkflow('bulk', 'provider'));
    expect($policy->delay($waiting))->toBe(50);
    $this->travel(50)->seconds();
    expect($policy->delay($waiting))->toBeNull();
    $another = runningLimitContext(limitedWorkflow('bulk', 'provider'));
    expect($policy->delay($another))->toBe(10);
});

it('does not charge the same current attempt twice even after its bucket is pruned', function () {
    $context = runningLimitContext(limitedWorkflow());
    $policy = new RateLimit('slow', 'account');
    expect($policy->delay($context))->toBeNull();
    $before = DB::table('scarlett_throttles')->where('id', $policy->id())->sole();
    expect($policy->delay($context))->toBeNull()
        ->and(DB::table('scarlett_throttles')->where('id', $policy->id())->sole())->toEqual($before);
    $this->travel(60)->seconds();
    $this->artisan('scarlett:prune-throttles')->assertExitCode(0);
    expect(DB::table('scarlett_throttles')->where('id', $policy->id())->exists())->toBeFalse();
    expect($policy->delay($context))->toBeNull();
    expect(DB::table('scarlett_throttles')->where('id', $policy->id())->exists())->toBeFalse();
});

it('spends retries only for failed calls and rechecks quota before the next call', function () {
    $flow = limitedWorkflow(fail: true);
    runStep($flow->id, 'call');
    $step = $flow->steps()->sole();
    $oldAttempt = $step->attempt;
    expect($step->failures)->toBe(1)->and($step->available_at->equalTo(now()->addSeconds(5)))->toBeTrue()
        ->and(RateLimitedStep::$calls)->toBe([$step->id]);
    $this->travel(5)->seconds();
    runStep($flow->id, 'call');
    expect($step->fresh()->failures)->toBe(1)->and($step->fresh()->available_at->equalTo(now()->addSeconds(55)))->toBeTrue()
        ->and(RateLimitedStep::$calls)->toBe([$step->id]);
    $this->travel(55)->seconds();
    runStep($flow->id, 'call');
    expect($step->fresh()->failures)->toBe(2)->and(RateLimitedStep::$calls)->toBe([$step->id, $step->id])
        ->and($step->fresh()->attempt)->not->toBe($oldAttempt);
    $this->travel(5)->seconds();
    runStep($flow->id, 'call');
    expect($step->fresh()->failures)->toBe(2)->and(RateLimitedStep::$calls)->toHaveCount(2);
    $this->travel(55)->seconds();
    runStep($flow->id, 'call');
    expect($step->fresh()->failures)->toBe(3)->and(statesOf($flow->id))->toBe(['call' => 'failed'])
        ->and(RateLimitedStep::$calls)->toBe([$step->id, $step->id, $step->id]);
    $this->travel(60)->seconds();
    runStep($flow->id, 'call');
    expect(RateLimitedStep::$calls)->toHaveCount(3)->and($step->fresh()->failures)->toBe(3);
});

it('rejects cancelled expired and superseded attempts without consuming another allowance', function (string $loss) {
    $flow = limitedWorkflow();
    $context = runningLimitContext($flow);
    $engine = app(Engine::class);
    match ($loss) {
        'cancel' => $engine->cancelWorkflow($flow->id),
        'expire' => $flow->steps()->sole()->update(['lease_until' => now()]),
        'retry' => $engine->decide($context->stepId, $context->attempt, Decision::retry(0)),
    };
    $policy = new RateLimit('slow', 'account');
    expect(fn () => $policy->delay($context))->toThrow(LogicException::class);
    expect(DB::table('scarlett_throttles')->where('id', $policy->id())->exists())->toBeFalse();
})->with(['cancel', 'expire', 'retry']);

it('rounds the remaining wait up and preserves the other account allowance', function () {
    $this->travelTo(now()->startOfSecond()->addMilliseconds(400));
    $first = new RateLimit('fast', 'one');
    expect($first->delay(runningLimitContext(limitedWorkflow('fast', 'one'))))->toBeNull();
    $this->travelTo(now()->addMilliseconds(9600));
    $waiting = runningLimitContext(limitedWorkflow('fast', 'one'));
    expect($first->delay($waiting))->toBe(1);
    expect((new RateLimit('fast', 'two'))->delay(runningLimitContext(limitedWorkflow('fast', 'two'))))->toBeNull();
    $this->travelTo(now()->addMilliseconds(400));
    expect($first->delay($waiting))->toBeNull();
});

it('rejects malformed named policies instead of silently running unthrottled', function (mixed $definition) {
    config(['scarlett.throttles.bad' => $definition]);
    expect(fn () => new RateLimit('bad', 'account'))->toThrow(InvalidArgumentException::class);
})->with(array_map(fn ($definition) => [$definition], [null, [], ['max_requests' => 0, 'per_seconds' => 60], ['max_requests' => 1, 'per_seconds' => 0], ['max_requests' => '1', 'per_seconds' => 10], ['max_requests' => 1, 'per_seconds' => -1], ['max_requests' => 1, 'per_seconds' => 1.5]]));

it('rejects a policy name or sharing key that is not valid UTF-8 before any quota is touched', function (string $name, string $key) {
    config(['scarlett.throttles' => [$name => ['max_requests' => 1, 'per_seconds' => 60]]]);

    expect(fn () => new RateLimit($name, $key))->toThrow(InvalidArgumentException::class, 'valid UTF-8');
})->with([
    'invalid policy name' => ["caf\xE9", 'account'],
    'invalid sharing key' => ['slow', "\xFF"],
]);

it('keeps a valid UTF-8 sharing key usable as a quota identity', function () {
    config(['scarlett.throttles' => ['slow' => ['max_requests' => 1, 'per_seconds' => 60]]]);

    $policy = new RateLimit('slow', 'café 名前');

    expect($policy->id())->toHaveLength(64)
        ->and($policy->delay(runningLimitContext(limitedWorkflow('slow', 'café 名前'))))->toBeNull();
});

it('refuses a window that stops being representable after the policy was accepted', function () {
    $boundary = intdiv(PHP_INT_MAX - now()->getTimestampMs(), ThrottleReservations::MILLISECONDS_PER_SECOND);
    config(['scarlett.throttles.slow.per_seconds' => $boundary]);
    $policy = new RateLimit('slow', 'account');
    $context = runningLimitContext(limitedWorkflow());
    $this->travel(1)->seconds();

    expect(fn () => $policy->delay($context))
        ->toThrow(InvalidArgumentException::class, 'representable');
});

it('accepts a window that is representable when the quota is checked', function () {
    config(['scarlett.throttles.slow.per_seconds' => 60]);
    $policy = new RateLimit('slow', 'account');
    $this->travel(1)->seconds();

    expect($policy->delay(runningLimitContext(limitedWorkflow())))->toBeNull();
});

it('rolls quota and receipt back together without disturbing another policy', function () {
    $first = runningLimitContext(limitedWorkflow());
    $other = new RateLimit('fast', 'account');
    expect($other->delay(runningLimitContext(limitedWorkflow('fast'))))->toBeNull();
    $snapshot = DB::table('scarlett_throttles')->where('id', $other->id())->sole();
    $policy = new RateLimit('slow', 'account');
    expect(fn () => DB::transaction(function () use ($policy, $first) {
        expect($policy->delay($first))->toBeNull();
        throw new RuntimeException('rollback');
    }))->toThrow(RuntimeException::class, 'rollback');
    expect(DB::table('scarlett_throttles')->where('id', $policy->id())->exists())->toBeFalse()
        ->and(DB::table('scarlett_throttles')->where('id', $other->id())->sole())->toEqual($snapshot)
        ->and(StepRecord::findOrFail($first->stepId)->throttle_reservations)->toBeNull();
    expect($policy->delay($first))->toBeNull();
});

it('rechecks lease expiry after acquiring the shared quota lock', function () {
    $context = runningLimitContext(limitedWorkflow());
    $policy = new RateLimit('slow', 'account');
    DB::listen(function ($query) {
        if (str_starts_with(strtolower($query->sql), 'select') && str_contains($query->sql, 'scarlett_throttles')) {
            Carbon::setTestNow(now()->addSeconds(config('scarlett.lease_seconds')));
        }
    });
    expect(fn () => $policy->delay($context))->toThrow(LogicException::class, 'lost ownership');
    expect(DB::table('scarlett_throttles')->where('id', $policy->id())->exists())->toBeFalse();
});

it('keeps an admitted allowance after worker loss and charges the recovered attempt separately', function () {
    $flow = limitedWorkflow();
    $context = runningLimitContext($flow);
    $policy = new RateLimit('slow', 'account');
    expect($policy->delay($context))->toBeNull();
    $flow->steps()->sole()->update(['lease_until' => now()]);
    expect(app(Engine::class)->recover($context->stepId))->toBeTrue();
    $this->travel(5)->seconds();
    $newContext = runningLimitContext($flow);
    expect($policy->delay($newContext))->toBe(55);
    $this->travel(55)->seconds();
    expect($policy->delay($newContext))->toBeNull()
        ->and($flow->steps()->sole()->throttle_reservations['attempt'])->toBe($newContext->attempt);
});

it('refuses inconsistent worker quotas until the existing window expires', function (int $seconds) {
    $policy = new RateLimit('slow', 'account');
    expect($policy->delay(runningLimitContext(limitedWorkflow())))->toBeNull();
    config(['scarlett.throttles.slow.per_seconds' => $seconds]);
    $changed = new RateLimit('slow', 'account');
    $context = runningLimitContext(limitedWorkflow());
    expect(fn () => $changed->delay($context))->toThrow(LogicException::class, 'different limits');
    $this->travel(60)->seconds();
    expect($changed->delay($context))->toBeNull();
    expect($changed->delay(runningLimitContext(limitedWorkflow())))->toBe($seconds);
})->with([10, 120]);

it('prunes only expired identities with bounded batches and rejects invalid options', function () {
    $slow = new RateLimit('slow', 'account');
    $fast = new RateLimit('fast', 'account');
    expect($slow->delay(runningLimitContext(limitedWorkflow())))->toBeNull();
    expect($fast->delay(runningLimitContext(limitedWorkflow('fast'))))->toBeNull();
    $snapshot = DB::table('scarlett_throttles')->where('id', $slow->id())->sole();
    $this->travel(10)->seconds();
    $this->artisan('scarlett:prune-throttles', ['--batch' => 0])->assertExitCode(2);
    expect(DB::table('scarlett_throttles')->where('id', $fast->id())->exists())->toBeTrue();
    $this->artisan('scarlett:prune-throttles', ['--batch' => 1])->assertExitCode(0);
    expect(DB::table('scarlett_throttles')->where('id', $fast->id())->exists())->toBeFalse()
        ->and(DB::table('scarlett_throttles')->where('id', $slow->id())->sole())->toEqual($snapshot);
});

it('deletes expired throttle buckets one batch per statement and never removes a renewed bucket', function () {
    $expired = [];
    for ($index = 0; $index < 250; $index++) {
        $expired[] = ['id' => 'expired-'.$index, 'grants' => '[]', 'expires_at' => 0, 'max_requests' => 1, 'per_seconds' => 60];
    }
    DB::table('scarlett_throttles')->insert($expired);
    DB::table('scarlett_throttles')->insert(['id' => 'renewed', 'grants' => '[]', 'expires_at' => now()->getTimestampMs() + 60000, 'max_requests' => 1, 'per_seconds' => 60]);
    DB::flushQueryLog();
    DB::enableQueryLog();

    $removed = app(ThrottleReservations::class)->pruneExpired(100);
    $deletes = collect(DB::getQueryLog())->filter(fn (array $query): bool => str_starts_with(strtolower($query['query']), 'delete'))->count();
    DB::disableQueryLog();

    expect($removed)->toBe(250)
        ->and($deletes)->toBe(3)
        ->and(DB::table('scarlett_throttles')->pluck('id')->all())->toBe(['renewed']);
});

it('installs upgrades repeats and safely rolls back the throttle schema', function () {
    $migration = require __DIR__.'/../../database/migrations/2026_11_14_000009_add_throttle_reservations.php';
    $flow = limitedWorkflow();
    $step = $flow->steps()->sole();
    $migration->down();
    expect(Schema::hasTable('scarlett_throttles'))->toBeFalse()
        ->and(DB::table('scarlett_steps')->where('id', $step->id)->value('attempt'))->toBe($step->attempt);
    $migration->up();
    $migration->up();
    expect($step->fresh()->throttle_reservations)->toBeNull();
    $context = runningLimitContext($flow);
    $policy = new RateLimit('slow', 'account');
    expect($policy->delay($context))->toBeNull();
    expect(fn () => $migration->down())->toThrow(RuntimeException::class, 'active throttle windows');
    $receipt = $step->fresh()->throttle_reservations;
    expect($receipt['attempt'])->toBe($step->attempt)->and($receipt['limits'])->toBe([$policy->id()])
        ->and(array_keys($receipt))->toEqualCanonicalizing(['attempt', 'limits']);
    $this->travel(60)->seconds();
    $migration->down();
    expect(Schema::hasColumn('scarlett_steps', 'throttle_reservations'))->toBeFalse();
    $migration->up();
    expect($step->fresh()->throttle_reservations)->toBeNull();
});
