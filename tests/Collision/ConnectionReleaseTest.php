<?php

declare(strict_types=1);

/**
 * Checks releasing and reopening real database handles at execution boundaries.
 *
 * Inspect existing PDO handles directly to distinguish reuse from reconnection.
 * Transactions, secondary handles, persistent connections, and after-commit callbacks
 * must remain intact. Lifecycle cases verify actual stored results and guarded writes
 * after reconnection, including quota waits and nested runtime-child verification.
 */

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Scarlett\Definition\ExecutionOptions;
use Scarlett\Definition\Node;
use Scarlett\Definition\Workflow;
use Scarlett\Engine;
use Scarlett\Execution\ConnectionRelease;
use Scarlett\Execution\Context;
use Scarlett\Failures\Runner as FailureRunner;
use Scarlett\Failures\Status;
use Scarlett\Models\FailureRecord;
use Scarlett\Models\WorkflowRecord;
use Scarlett\Tests\Fixtures\ConnectionAwareStep;
use Scarlett\Tests\Fixtures\RateLimitedStep;
use Scarlett\Tests\Fixtures\RuntimeParent;

beforeEach(function () {
    Queue::fake();
    config(['scarlett.release_database_connection' => true]);
    ConnectionAwareStep::$observations = [];
    ConnectionAwareStep::$verificationPasses = true;
});

function connectionWorkflow(bool $fail = false): WorkflowRecord
{
    return app(Engine::class)->start(new Workflow([
        new Node('action', ConnectionAwareStep::class, inputs: compact('fail'), execution: new ExecutionOptions(attempts: 1)),
        new Node('next', ConnectionAwareStep::class, stage: 1),
    ]));
}

it('releases an open connection without losing committed data and reconnects on demand', function () {
    $flow = connectionWorkflow();
    $connection = DB::connection();
    $handle = WeakReference::create($connection->getPdo());
    expect($handle->get() instanceof PDO)->toBeTrue();
    expect(app(ConnectionRelease::class)->release())->toBeTrue()
        ->and($connection->getRawPdo())->toBeNull()->and($handle->get())->toBeNull();
    expect(app(ConnectionRelease::class)->release())->toBeFalse();
    expect($flow->fresh()->status)->toBe('running')
        ->and($connection->getRawPdo() instanceof PDO)->toBeTrue();
});

it('leaves disabled connection handling and other named connections untouched', function () {
    $connection = DB::connection();
    $pdo = $connection->getPdo();
    config(['scarlett.release_database_connection' => false]);
    expect(app(ConnectionRelease::class)->release())->toBeFalse()->and($connection->getRawPdo())->toBe($pdo);
    config(['scarlett.release_database_connection' => true, 'database.connections.other' => $connection->getConfig()]);
    $other = DB::connection('other')->getPdo();
    expect(app(ConnectionRelease::class)->release())->toBeTrue()
        ->and(DB::connection('other')->getRawPdo())->toBe($other);
});

it('preserves nested transactions and their after-commit callbacks', function () {
    $flow = connectionWorkflow();
    $connection = DB::connection();
    $pdo = $connection->getPdo();
    $committed = false;
    DB::transaction(function () use ($flow, $connection, $pdo, &$committed): void {
        DB::afterCommit(function () use (&$committed): void {
            $committed = true;
        });
        DB::transaction(function () use ($flow, $connection, $pdo): void {
            $flow->update(['metadata' => ['inside' => true]]);
            expect(app(ConnectionRelease::class)->release())->toBeFalse()
                ->and($connection->transactionLevel())->toBe(2)->and($connection->getRawPdo())->toBe($pdo);
        });
        expect($committed)->toBeFalse()->and(app(ConnectionRelease::class)->release())->toBeFalse();
    });
    expect($committed)->toBeTrue()->and($flow->fresh()->metadata)->toBe(['inside' => true])
        ->and(app(ConnectionRelease::class)->release())->toBeTrue();
});

it('does not disconnect a transaction opened directly through PDO', function () {
    $connection = DB::connection();
    $pdo = $connection->getPdo();
    $pdo->beginTransaction();
    try {
        expect($connection->transactionLevel())->toBe(0)
            ->and(app(ConnectionRelease::class)->release())->toBeFalse()
            ->and($pdo->inTransaction())->toBeTrue()->and($connection->getRawPdo())->toBe($pdo);
    } finally {
        $pdo->rollBack();
    }
});

it('preserves transactions on secondary PDO handles', function (string $kind) {
    $connection = DB::connection();
    config(['database.connections.secondary' => $connection->getConfig()]);
    $secondary = DB::connection('secondary')->getPdo();
    $setter = $kind === 'read' ? 'setReadPdo' : 'setDirectPdo';
    $connection->{$setter}($secondary);
    $secondary->beginTransaction();
    try {
        expect($connection->transactionLevel())->toBe(0)
            ->and(app(ConnectionRelease::class)->release())->toBeFalse()
            ->and($secondary->inTransaction())->toBeTrue();
    } finally {
        $secondary->rollBack();
        $connection->{$setter}(null);
    }
})->with(['read', 'direct']);

it('skips a connection configured for persistent PDO without changing its handle', function () {
    $previous = DB::getDefaultConnection();
    $connection = DB::connection();
    $configuration = $connection->getConfig();
    $configuration['options'][PDO::ATTR_PERSISTENT] = true;
    config(['database.connections.persistent_probe' => $configuration]);
    $probe = DB::connection('persistent_probe');
    /**
     * Use a real existing handle to exercise the configuration guard without
     * introducing persistent test sessions into the database server's pool.
     */
    $pdo = $connection->getPdo();
    $probe->setPdo($pdo);
    DB::setDefaultConnection('persistent_probe');
    try {
        expect(app(ConnectionRelease::class)->release())->toBeFalse()->and($probe->getRawPdo())->toBe($pdo);
    } finally {
        DB::setDefaultConnection($previous);
        DB::purge('persistent_probe');
    }
});

it('releases around actions and verification while preserving guarded writes and sequential progression', function () {
    $flow = connectionWorkflow();
    expect(statesOf($flow->id))->toBe(['action' => 'queued', 'next' => 'pending']);
    runStep($flow->id, 'action');
    expect(DB::connection()->getRawPdo())->toBeNull()
        ->and(ConnectionAwareStep::$observations)->toBe([['action', true], ['verify', true]])
        ->and(statesOf($flow->id))->toBe(['action' => 'completed', 'next' => 'queued'])
        ->and($flow->fresh()->metadata)->toBe(['saved' => true]);
    runStep($flow->id, 'next');
    expect($flow->fresh()->status)->toBe('completed');
});

it('releases around durable failure handling without losing failure reasons or guarded writes', function () {
    $flow = connectionWorkflow(fail: true);
    runStep($flow->id, 'action');
    expect(DB::connection()->getRawPdo())->toBeNull();
    $step = $flow->steps()->where('key', 'action')->sole();
    $failure = FailureRecord::where('step_id', $step->id)->sole();
    expect($failure->status)->toBe(Status::Queued)->and($step->error)->toBe('Action failed.');
    app(FailureRunner::class)->run($failure->id, $failure->attempt);
    expect(DB::connection()->getRawPdo())->toBeNull()
        ->and(ConnectionAwareStep::$observations)->toBe([['action', true], ['failure', true]])
        ->and($failure->fresh()->status)->toBe(Status::Completed)
        ->and($flow->fresh()->metadata)->toBe(['handled' => true]);
});

it('allows explicit release after input preparation and retains cancellation protection', function () {
    $flow = connectionWorkflow();
    $step = $flow->steps()->where('key', 'action')->sole();
    app(Engine::class)->claim($step->id, $step->attempt);
    $context = new Context($flow->id, $step->id, $step->attempt, 0);
    expect($context->isCurrent())->toBeTrue()->and($context->releaseDatabaseConnection())->toBeTrue();
    app(Engine::class)->cancelWorkflow($flow->id);
    $written = false;
    expect($context->whileCurrent(function () use (&$written): void {
        $written = true;
    }))->toBeFalse()
        ->and($written)->toBeFalse()->and($context->heartbeat())->toBeFalse();
});

it('reconnects for verification retries without repeating a completed action', function () {
    $this->freezeSecond();
    ConnectionAwareStep::$verificationPasses = false;
    $flow = connectionWorkflow();
    runStep($flow->id, 'action');
    $step = $flow->steps()->where('key', 'action')->sole();
    expect($step->action_completed)->toBeTrue()->and((string) $step->state)->toBe('queued')
        ->and(statesOf($flow->id)['next'])->toBe('pending');
    ConnectionAwareStep::$verificationPasses = true;
    $this->travelTo($step->available_at);
    runStep($flow->id, 'action');
    expect(ConnectionAwareStep::$observations)->toBe([['action', true], ['verify', true], ['verify', true]])
        ->and(statesOf($flow->id))->toBe(['action' => 'completed', 'next' => 'queued']);
});

it('preserves shared quota waits separately from bounded retries after reconnecting', function () {
    $this->freezeSecond();
    RateLimitedStep::$calls = [];
    config(['scarlett.throttles' => ['one' => ['max_requests' => 1, 'per_seconds' => 60]]]);
    $flow = app(Engine::class)->start(new Workflow([
        new Node('action', RateLimitedStep::class, inputs: ['policy' => 'one', 'account' => 'release', 'fail' => true], execution: new ExecutionOptions(attempts: 2)),
    ]));
    runStep($flow->id, 'action');
    $step = $flow->steps()->sole();
    expect($step->failures)->toBe(1)->and(RateLimitedStep::$calls)->toBe([$step->id]);
    $this->travel(5)->seconds();
    runStep($flow->id, 'action');
    expect($step->fresh()->failures)->toBe(1)->and(RateLimitedStep::$calls)->toBe([$step->id]);
    $this->travel(55)->seconds();
    runStep($flow->id, 'action');
    expect($step->fresh()->failures)->toBe(2)->and((string) $step->fresh()->state)->toBe('failed')
        ->and(RateLimitedStep::$calls)->toBe([$step->id, $step->id]);
});

it('preserves nested runtime backup results and successor barriers after reconnecting', function () {
    RuntimeParent::$actions = [];
    RuntimeParent::$verified = [];
    RuntimeParent::$verificationPasses = true;
    $flow = app(Engine::class)->start(new Workflow([
        new Node('parent', RuntimeParent::class, inputs: ['depth' => 2]),
        new Node('next', ConnectionAwareStep::class, stage: 1),
    ]));
    runStep($flow->id, 'parent');
    $backup = $flow->steps()->where('parent_key', 'parent')->sole();
    runStep($flow->id, $backup->key);
    $leaf = $flow->steps()->where('parent_key', $backup->key)->sole();
    runStep($flow->id, $leaf->key);
    expect(statesOf($flow->id)['next'])->toBe('pending');
    runStep($flow->id, $backup->key);
    runStep($flow->id, 'parent');
    expect(RuntimeParent::$actions)->toBe([2, 1, 0])
        ->and(RuntimeParent::$verified)->toBe([[0, 'backup result'], [1, 'backup result'], [2, 'backup result']])
        ->and(statesOf($flow->id)['next'])->toBe('queued');
    runStep($flow->id, 'next');
    expect($flow->fresh()->status)->toBe('completed');
});

it('keeps rollback and after-commit cancellation intact inside a guarded write', function () {
    $flow = connectionWorkflow();
    $step = $flow->steps()->where('key', 'action')->sole();
    app(Engine::class)->claim($step->id, $step->attempt);
    $context = new Context($flow->id, $step->id, $step->attempt, 0);
    $committed = false;
    try {
        $context->whileCurrent(function () use ($flow, $context, &$committed): void {
            $flow->update(['metadata' => ['must_rollback' => true]]);
            DB::afterCommit(function () use (&$committed): void {
                $committed = true;
            });
            expect($context->releaseDatabaseConnection())->toBeFalse();
            throw new RuntimeException('Rollback guarded write.');
        });
    } catch (RuntimeException $exception) {
        expect($exception->getMessage())->toBe('Rollback guarded write.');
    }
    expect($flow->fresh()->metadata)->toBe([])->and($committed)->toBeFalse()
        ->and($context->isCurrent())->toBeTrue()->and($context->heartbeat())->toBeTrue();
});
