<?php

declare(strict_types=1);

/**
 * Separates a business return value from a successfully saved and verified result.
 *
 * Unsupported objects and injected write errors fail at persistence, after the action
 * has run. Verification must use the decoded stored type on the first call and retries.
 * A mapper that revokes authority during decode tests the recheck immediately before
 * verification, where an earlier ownership read is no longer sufficient.
 */

use Illuminate\Database\Events\TransactionRolledBack;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Queue;
use Scarlett\Contracts\PayloadMapper;
use Scarlett\Definition\ExecutionOptions;
use Scarlett\Definition\Node;
use Scarlett\Definition\Workflow;
use Scarlett\Engine;
use Scarlett\Execution\Context;
use Scarlett\Execution\Decision;
use Scarlett\History;
use Scarlett\Models\StepRecord;
use Scarlett\Step;
use Scarlett\Tests\Fixtures\Amount;
use Scarlett\Tests\Fixtures\AmountMapper;
use Scarlett\Tests\Fixtures\ControlledStep;
use Scarlett\Tests\Fixtures\DateResultStep;
use Scarlett\Tests\Fixtures\UnstorableResultStep;
use Scarlett\Tests\Fixtures\VerifiedAmountStep;

beforeEach(function () {
    Queue::fake();
    ControlledStep::$actions = [];
});

it('fails an unstorable successful action without invoking it again', function () {
    $flow = app(Engine::class)->start(new Workflow([
        new Node('invalid', UnstorableResultStep::class, inputs: ['name' => 'invalid']),
        controlled('healthy'), controlled('throws', 'throw'),
    ]));
    expect(statesOf($flow->id))->toBe(['healthy' => 'queued', 'invalid' => 'queued', 'throws' => 'queued']);
    runStep($flow->id, 'invalid');
    runStep($flow->id, 'healthy');
    runStep($flow->id, 'throws');
    expect(statesOf($flow->id))->toBe(['healthy' => 'completed', 'invalid' => 'failed', 'throws' => 'queued']);
    expect(ControlledStep::$actions)->toBe(['invalid' => 1, 'healthy' => 1, 'throws' => 1]);
    $invalid = $flow->steps()->where('key', 'invalid')->sole();
    expect($invalid->failures)->toBe(0)->and($invalid->error)->toContain('Unsupported payload type');
});

it('fails a result write error without classifying it as an action failure', function () {
    $flow = app(Engine::class)->start(new Workflow([controlled('write'), controlled('untouched')]));
    StepRecord::saving(function (StepRecord $step): void {
        if ($step->key === 'write' && $step->isDirty('has_result')) {
            throw new RuntimeException('Result persistence unavailable.');
        }
    });
    runStep($flow->id, 'write');
    expect(statesOf($flow->id))->toBe(['untouched' => 'queued', 'write' => 'failed']);
    expect(ControlledStep::$actions)->toBe(['write' => 1]);
    expect($flow->steps()->where('key', 'write')->sole()->error)->toBe('Result persistence unavailable.');
});

/** A database error carrying the stored payload as its binding, as Laravel reports a refused write. */
function resultPersistenceDatabaseError(string $message, array $errorInfo, mixed $binding): QueryException
{
    $pdo = new PDOException($message);
    $pdo->errorInfo = $errorInfo;

    return new QueryException('testing', 'update scarlett_steps set result = ?', [$binding], $pdo);
}

it('retries a result save that hits a passing database error under the step\'s own rules', function () {
    $flow = app(Engine::class)->start(new Workflow([new Node('write', ControlledStep::class, inputs: ['name' => 'write', 'value' => 'Private words a person wrote.'], execution: new ExecutionOptions(attempts: 2, backoffSeconds: [3]))]));
    expect($flow->steps()->where('key', 'write')->sole()->action_completed)->toBeFalse();
    $remaining = 1;
    StepRecord::saving(function (StepRecord $step) use (&$remaining): void {
        if ($remaining > 0 && $step->key === 'write' && $step->isDirty('has_result')) {
            $remaining--;
            throw resultPersistenceDatabaseError('SQLSTATE[HY000]: General error: 2006 MySQL server has gone away', ['HY000', 2006, 'MySQL server has gone away'], $step->getAttributes()['result']);
        }
    });
    runStep($flow->id, 'write');
    $write = $flow->steps()->where('key', 'write')->sole();
    expect((string) $write->state)->toBe('queued')
        ->and($write->failures)->toBe(1)
        ->and($write->action_completed)->toBeFalse()
        ->and($write->error)->toBe('Illuminate\Database\QueryException [SQLSTATE HY000] (details in the application log)');
    $this->travelTo($write->available_at);
    runStep($flow->id, 'write');
    expect((string) $write->fresh()->state)->toBe('completed')
        ->and(ControlledStep::$actions)->toBe(['write' => 2]);
});

it('verifies a result whose save committed before its connection dropped', function () {
    $flow = app(Engine::class)->start(new Workflow([new Node('write', ControlledStep::class, inputs: ['name' => 'write', 'value' => 'saved value'], execution: new ExecutionOptions(attempts: 2, backoffSeconds: [3]))]));
    expect($flow->steps()->where('key', 'write')->sole()->action_completed)->toBeFalse();
    StepRecord::saved(function (StepRecord $step): void {
        if ($step->key === 'write' && $step->wasChanged('has_result')) {
            DB::afterCommit(fn () => throw resultPersistenceDatabaseError('SQLSTATE[HY000]: General error: 2006 MySQL server has gone away', ['HY000', 2006, 'MySQL server has gone away'], 'saved value'));
        }
    });
    runStep($flow->id, 'write');
    $write = $flow->steps()->where('key', 'write')->sole();
    expect((string) $write->state)->toBe('completed')
        ->and($write->failures)->toBe(0)
        ->and($write->error)->toBeNull()
        ->and(ControlledStep::$actions)->toBe(['write' => 1]);
});

it('stores a database error that refuses a result save without the text it quotes', function () {
    Exceptions::fake();
    $flow = app(Engine::class)->start(new Workflow([new Node('write', ControlledStep::class, inputs: ['name' => 'write', 'value' => 'Private words a person wrote.'])]));
    expect($flow->steps()->where('key', 'write')->sole()->error)->toBeNull();
    StepRecord::saving(function (StepRecord $step): void {
        if ($step->key === 'write' && $step->isDirty('has_result')) {
            throw resultPersistenceDatabaseError('SQLSTATE[23000]: Integrity constraint violation: 19 CHECK constraint failed', ['23000', 19, 'CHECK constraint failed'], $step->getAttributes()['result']);
        }
    });
    runStep($flow->id, 'write');
    $write = $flow->steps()->where('key', 'write')->sole();
    expect((string) $write->state)->toBe('failed')
        ->and($write->error)->toBe('Illuminate\Database\QueryException [SQLSTATE 23000] (details in the application log)')
        ->and(DB::table('scarlett_history')->where('step_id', $write->id)->where('reason', 'like', '%Private words%')->exists())->toBeFalse()
        ->and(ControlledStep::$actions)->toBe(['write' => 1]);
    Exceptions::assertReported(fn (QueryException $exception): bool => str_contains($exception->getMessage(), 'Private words a person wrote.'));
});

it('verifies the same restored result type on first execution and retries', function () {
    $this->freezeSecond();
    DateResultStep::$verifiedTypes = [];
    $flow = app(Engine::class)->start(new Workflow([
        new Node('date', DateResultStep::class, inputs: ['name' => 'date']),
    ]));
    runStep($flow->id, 'date');
    expect(statesOf($flow->id))->toBe(['date' => 'queued']);
    $this->travel(5)->seconds();
    runStep($flow->id, 'date');
    expect(statesOf($flow->id))->toBe(['date' => 'completed']);
    expect(ControlledStep::$actions)->toBe(['date' => 1]);
    expect(DateResultStep::$verifiedTypes)->toBe([DateTimeImmutable::class, DateTimeImmutable::class]);
});

it('does not verify after ownership is revoked while restoring the saved result', function () {
    VerifiedAmountStep::$verifications = 0;
    config(['scarlett.payload_mappers' => ['amount' => AmountMapper::class]]);
    $flow = app(Engine::class)->start(new Workflow([new Node('owned', VerifiedAmountStep::class)]));
    app()->instance(AmountMapper::class, new class($flow->id) implements PayloadMapper
    {
        public function __construct(private string $workflow) {}

        public function supports(object $value): bool
        {
            return $value instanceof Amount;
        }

        public function encode(object $value): mixed
        {
            return $value->minorUnits;
        }

        public function decode(mixed $value): object
        {
            app(Engine::class)->cancelWorkflow($this->workflow);

            return new Amount($value);
        }
    });
    runStep($flow->id, 'owned');
    expect($flow->fresh()->status)->toBe('cancelled');
    expect(VerifiedAmountStep::$verifications)->toBe(0);
});

it('exits cleanly when a step is deleted concurrently with a passing database error during result save', function () {
    $flow = app(Engine::class)->start(new Workflow([
        new Node('vanish', ControlledStep::class, inputs: ['name' => 'vanish', 'value' => 'saved']),
    ]));
    $threw = false;
    $deleted = false;
    StepRecord::saving(function (StepRecord $step) use (&$threw): void {
        if (! $threw && $step->key === 'vanish' && $step->isDirty('has_result')) {
            $threw = true;
            throw resultPersistenceDatabaseError('SQLSTATE[40001]: Serialization failure: 1213 Deadlock found', ['40001', 1213, 'Deadlock found'], $step->getAttributes()['result']);
        }
    });
    Event::listen(TransactionRolledBack::class, function () use ($flow, &$threw, &$deleted): void {
        if ($threw && ! $deleted) {
            $deleted = true;
            app(History::class)->delete($flow->id, includingRunning: true);
        }
    });

    runStep($flow->id, 'vanish');

    expect($deleted)->toBeTrue()
        ->and(StepRecord::where('workflow_id', $flow->id)->exists())->toBeFalse();
});

it('keeps database error bindings out of step records when onException crashes or verification hits a database error', function () {
    Exceptions::fake();

    $flow = app(Engine::class)->start(new Workflow([
        new Node('crash', HandlerDatabaseCrashTestStep::class),
        new Node('verify-db', VerifyDatabaseErrorTestStep::class, execution: new ExecutionOptions(verificationAttempts: 1, verificationBackoffSeconds: [0])),
    ]));

    runStep($flow->id, 'crash');
    runStep($flow->id, 'verify-db');

    $crash = $flow->steps()->where('key', 'crash')->sole();
    $verify = $flow->steps()->where('key', 'verify-db')->sole();

    expect((string) $crash->state)->toBe('failed')
        ->and($crash->error)->toBe('Illuminate\Database\QueryException [SQLSTATE 23000] (details in the application log)')
        ->and($crash->error)->not->toContain('secret-handler-binding')
        ->and((string) $verify->state)->toBe('failed')
        ->and($verify->error)->toBe('Illuminate\Database\QueryException [SQLSTATE 23000] (details in the application log)')
        ->and($verify->error)->not->toContain('secret-verify-binding');
    Exceptions::assertReported(fn (QueryException $exception): bool => str_contains($exception->getMessage(), 'secret-handler-binding'));
    Exceptions::assertReported(fn (QueryException $exception): bool => str_contains($exception->getMessage(), 'secret-verify-binding'));
});

final class HandlerDatabaseCrashTestStep extends Step
{
    public function handle(): string
    {
        throw new RuntimeException('trigger-handler');
    }

    public function onException(Throwable $exception, Context $context): Decision
    {
        throw resultPersistenceDatabaseError('SQLSTATE[23000]: Integrity constraint violation', ['23000', 19, 'constraint'], 'secret-handler-binding');
    }
}

final class VerifyDatabaseErrorTestStep extends Step
{
    public function handle(): string
    {
        return 'ok';
    }

    public function verify(Context $context, mixed $result): bool
    {
        throw resultPersistenceDatabaseError('SQLSTATE[23000]: Integrity constraint violation', ['23000', 19, 'constraint'], 'secret-verify-binding');
    }
}
