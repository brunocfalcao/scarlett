<?php

declare(strict_types=1);

/**
 * Checks the application's Artisan and configuration entry points into Scarlett.
 *
 * Tests create interrupted action and failure-hook records, then invoke recovery
 * with and without a workflow restriction. Configuration cases supply invalid values
 * before admission. Queue fakes expose accidental publication; scoped assertions
 * ensure repairing one workflow does not silently repair unrelated work too.
 */

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Scarlett\Configuration;
use Scarlett\Definition\ExecutionOptions;
use Scarlett\Definition\Node;
use Scarlett\Definition\SubjectSlot;
use Scarlett\Definition\Workflow;
use Scarlett\Engine;
use Scarlett\Facades\Scarlett;
use Scarlett\Failures\Status;
use Scarlett\Models\WorkflowRecord;
use Scarlett\Policies\RetryPolicy;
use Scarlett\Recovery;
use Scarlett\Tests\Fixtures\FailureAwareStep;

beforeEach(function () {
    Queue::fake();
    $this->freezeSecond();
    FailureAwareStep::$hookCalls = 0;
    FailureAwareStep::$hookThrows = false;
    FailureAwareStep::$constructionThrows = false;
});

it('recovers stalled steps and failure hooks globally or scoped to one workflow via scarlett:recover', function () {
    $engine = app(Engine::class);
    $engine->cooldown();
    $held = $engine->start(new Workflow([controlled('held')]));
    $engine->reopen();
    runStep($held->id, 'held');

    $healthy = $engine->start(new Workflow([controlled('healthy')]));
    $healthyStep = $healthy->steps()->sole();
    $engine->claim($healthyStep->id, $healthyStep->attempt);

    $lostDelivery = $engine->start(new Workflow([controlled('lost-delivery')]));
    DB::table('scarlett_deliveries')->where('step_id', $lostDelivery->steps()->sole()->id)->update(['sent_at' => null]);

    $expiredStepFlow = $engine->start(new Workflow([controlled('expired-step')]));
    $expiredStep = $expiredStepFlow->steps()->sole();
    $engine->claim($expiredStep->id, $expiredStep->attempt);
    $expiredStep->update(['lease_until' => now()->subSecond()]);

    $failedFlow = $engine->start(new Workflow([
        new Node('action', FailureAwareStep::class, inputs: ['name' => 'action', 'mode' => 'fail']),
    ]));
    runStep($failedFlow->id, 'action');
    $failure = $failedFlow->failures()->sole();
    $failure->update(['sent_at' => null]);

    expect(app(Recovery::class)->stalled())->toBeTrue();
    $this->artisan('scarlett:recover', ['workflow' => $lostDelivery->id])
        ->expectsOutput('Recovered 1 execution(s).')
        ->assertSuccessful();
    expect(DB::table('scarlett_deliveries')->where('step_id', $lostDelivery->steps()->sole()->id)->value('sent_at'))->not->toBeNull();
    expect(app(Recovery::class)->stalled($expiredStepFlow->id))->toBeTrue();
    expect(app(Recovery::class)->stalled($failedFlow->id))->toBeTrue();

    $this->artisan('scarlett:recover')
        ->expectsOutput('Recovered 2 execution(s).')
        ->assertSuccessful();

    expect((string) $expiredStep->fresh()->state)->toBe('queued')
        ->and($expiredStep->fresh()->failures)->toBe(1)
        ->and((string) $healthyStep->fresh()->state)->toBe('running')
        ->and(DB::table('scarlett_failures')->where('id', $failure->id)->value('sent_at'))->not->toBeNull()
        ->and(app(Recovery::class)->stalled())->toBeFalse();

    $this->artisan('scarlett:recover')
        ->expectsOutput('Recovered 0 execution(s).')
        ->assertSuccessful();
});

it('ignores queued failure hooks that are delayed or currently publishing during recovery scans', function () {
    $flow = app(Engine::class)->start(new Workflow([
        new Node('action', FailureAwareStep::class, inputs: ['name' => 'action', 'mode' => 'fail']),
    ]));
    runStep($flow->id, 'action');
    $failure = $flow->failures()->sole();

    $failure->update(['sent_at' => null, 'available_at' => now()->addMinute()]);
    expect(app(Recovery::class)->stalled($flow->id))->toBeFalse();

    $failure->update(['available_at' => now()->subSecond(), 'publishing_until' => now()->addMinute()]);
    expect(app(Recovery::class)->stalled($flow->id))->toBeFalse()
        ->and($failure->fresh()->status)->toBe(Status::Queued);
});

it('exposes admission, cooldown and slot workflows through the Scarlett facade', function () {
    $slot = new SubjectSlot('order', '100', 'sync');
    Scarlett::cooldown();
    $held = Scarlett::replace($slot, fn () => new Workflow([controlled('first')]));
    expect($held)->toBeInstanceOf(WorkflowRecord::class)
        ->and($held->status)->toBe('held')
        ->and(Scarlett::current($slot))->toBe($held->id);

    Scarlett::reopen();
    expect(statesOf($held->id))->toBe(['first' => 'queued']);
    runStep($held->id, 'first');
    expect($held->fresh()->status)->toBe('completed');
});

it('publishes the scarlett-config tag via vendor:publish', function () {
    $target = config_path('scarlett.php');
    $existed = is_file($target);
    $previous = $existed ? file_get_contents($target) : null;

    try {
        $this->artisan('vendor:publish', ['--tag' => 'scarlett-config', '--force' => true])
            ->assertSuccessful();
        expect(is_file($target))->toBeTrue()
            ->and(file_get_contents($target))->toBe(file_get_contents(__DIR__.'/../../config/scarlett.php'));
    } finally {
        if ($existed && $previous !== null) {
            file_put_contents($target, $previous);
        } elseif (is_file($target)) {
            unlink($target);
        }
    }
});

it('rejects invalid global Scarlett configuration values', function (string $key, mixed $value, string $message) {
    config(["scarlett.$key" => $value]);
    expect(fn () => app(Configuration::class)->validate())->toThrow(InvalidArgumentException::class, $message);
})->with([
    'zero lease_seconds' => ['lease_seconds', 0, 'lease_seconds'],
    'string worker_timeout' => ['worker_timeout', '10', 'worker_timeout'],
    'negative delivery_retry_seconds' => ['delivery_retry_seconds', -5, 'delivery_retry_seconds'],
    'zero transaction_attempts' => ['transaction_attempts', 0, 'transaction_attempts'],
    'invalid input_exception_handler' => ['input_exception_handler', stdClass::class, 'ExceptionHandler'],
    'associative input_terminal_exceptions' => ['input_terminal_exceptions', ['a' => RuntimeException::class], 'list'],
    'non-throwable input_terminal_exceptions' => ['input_terminal_exceptions', [stdClass::class], 'Throwable'],
    'empty queue' => ['queue', '', 'scarlett.queue'],
    'null queue' => ['queue', null, 'scarlett.queue'],
    'invalid utf8 queue' => ['queue', "\xFF", 'scarlett.queue'],
    'oversized queue' => ['queue', str_repeat('q', 256), 'scarlett.queue'],
    'empty connection' => ['connection', '', 'scarlett.connection'],
    'non-string connection' => ['connection', 123, 'scarlett.connection'],
    'invalid utf8 connection' => ['connection', "\xFF", 'scarlett.connection'],
    'oversized connection' => ['connection', str_repeat('c', 256), 'scarlett.connection'],
]);

it('rejects invalid registry, retry policy and throttle configuration before admission', function (string $key, mixed $value, string $message) {
    $original = config("scarlett.$key");
    try {
        config(["scarlett.$key" => $value]);

        expect(fn () => app(Configuration::class)->validate())
            ->toThrow(InvalidArgumentException::class, $message);
    } finally {
        config(["scarlett.$key" => $original]);
    }
})->with([
    'invalid retry policy' => ['retry_policy', stdClass::class, 'retry_policy'],
    'invalid enum alias' => ['enums', ['status' => stdClass::class], 'scarlett.enums'],
    'invalid model alias' => ['models', ['source' => stdClass::class], 'scarlett.models'],
    'invalid throttle limits' => ['throttles', ['bad' => ['max_requests' => 0, 'per_seconds' => 60]], 'scarlett.throttles'],
    'invalid states shape' => ['states', 'not-a-list', 'scarlett.states'],
    'invalid transition shape' => ['transitions', [['invalid']], 'scarlett.transitions'],
    'null payload mappers' => ['payload_mappers', null, 'scarlett.payload_mappers'],
    'string payload mappers' => ['payload_mappers', 'amount', 'scarlett.payload_mappers'],
]);

it('rejects invalid ExecutionOptions retry and verification policies before admission', function (ExecutionOptions $options) {
    expect(fn () => app(Engine::class)->start(new Workflow([
        new Node('bad', FailureAwareStep::class, inputs: ['name' => 'bad'], execution: $options),
    ])))->toThrow(InvalidArgumentException::class);
    Queue::assertNothingPushed();
})->with([
    'zero retry attempts' => [new ExecutionOptions(attempts: 0)],
    'empty retry backoff' => [new ExecutionOptions(backoffSeconds: [])],
    'non-list retry backoff' => [new ExecutionOptions(backoffSeconds: ['first' => 1])],
    'negative retry backoff' => [new ExecutionOptions(backoffSeconds: [-1])],
    'zero verification attempts' => [new ExecutionOptions(verificationAttempts: 0)],
    'empty verification backoff' => [new ExecutionOptions(verificationBackoffSeconds: [])],
    'non-list verification backoff' => [new ExecutionOptions(verificationBackoffSeconds: ['first' => 1])],
    'negative verification backoff' => [new ExecutionOptions(verificationBackoffSeconds: [-1])],
]);

it('rejects a retry backoff schedule that is not a list when a delay is requested', function (array $schedule) {
    config(['scarlett.retry.backoff_seconds' => $schedule]);

    expect(fn () => (new RetryPolicy)->delay(1))->toThrow(InvalidArgumentException::class, 'non-empty list');
})->with([
    'associative schedule' => [['first' => 5]],
    'gapped schedule' => [[1 => 5]],
]);

it('returns the scheduled delay for a list backoff and reuses its last entry', function () {
    config(['scarlett.retry.backoff_seconds' => [0, 5]]);

    expect((new RetryPolicy)->delay(1))->toBe(0)
        ->and((new RetryPolicy)->delay(2))->toBe(5)
        ->and((new RetryPolicy)->delay(9))->toBe(5);
});
