# Scarlett

Outcome-driven workflows for Laravel 13 and PHP 8.4+. MIT licensed.

Scarlett commits each outcome and identifies the next eligible work immediately.
There is no scheduler tick in normal progression. Laravel queue workers still
fetch jobs normally. Recovery may run periodically to replay lost deliveries and
replace expired execution attempts.

## Installation

The repository is a Composer package. Until it is registered on Packagist, add a
VCS repository for `https://github.com/brunocfalcao/scarlett` in your application's
`composer.json`, then require `brunocfalcao/scarlett:dev-main`.
For local development use a Composer path repository with symlinking enabled.

```sh
composer require brunocfalcao/scarlett:dev-main
php artisan migrate
php artisan vendor:publish --tag=scarlett-config
php artisan queue:work --queue=scarlett
```

Provider discovery registers configuration, migrations, and `scarlett:recover`.
Use one application database connection for Scarlett's records and transactions.
MySQL is the supported concurrent deployment database. SQLite is useful for local
and isolated tests; PostgreSQL has not been verified in this version.

## Define steps and workflows

```php
use Scarlett\Step;
use Scarlett\Definition\{Node, Workflow};
use Scarlett\Facades\Scarlett;
use Scarlett\Inputs\FromResult;

final class DoubleNumber extends Step
{
    public function __construct(private int $value) {}

    public function handle(): int
    {
        return $this->value * 2;
    }
}

$workflow = Scarlett::start(new Workflow([
    new Node('first', DoubleNumber::class, inputs: ['value' => 21]),
    new Node('second', DoubleNumber::class, stage: 1,
        inputs: ['value' => new FromResult('first')]),
]));
```

A `Node` stores a class reference and named input bindings. Definition validation
uses reflection only. It never constructs a business step or resolves a service.
The second constructor runs only after the first outcome and result are committed.

Lower stages precede higher stages within a sibling group. Equal-stage siblings
run in parallel; every earlier sibling is a prerequisite. `parent` attaches a
predefined child to another node. A parent performs and verifies its action, then
waits for its children. Children may bind the parent's committed action result.
`rescues` creates a recovery root activated by the named step's definitive failure
or stop. Recovery roots can have their own stages and predefined children.

`priority`, `queue`, and `connection` control publication order and queue routing.
Priority never overrides dependencies or preempts running work. Queue workers and
transport capacity determine actual execution order after publication.

## Lifecycle

Extend `Step` and declare a public, non-static `handle` method with your own typed
parameters and return type. Constructor and handler arguments come from explicit
named inputs. Defaults and variadic lists are supported. Reference parameters and
unknown/missing input names are rejected. Scalar types are not silently coerced.

Optional hooks:

- `before(Context): ?Decision` can skip, stop, fail, retry, throttle, or supply an
  acceptable result. Returning null proceeds to the action.
- `verify(Context, mixed $result): bool` must return true before completion.
  Verification retries restore the saved result and never repeat the action.
- `onException(Throwable, Context): Decision` classifies an action error. The
  default retries within the configured budget. Accepted results still verify.
- `throttle(): ?Throttle` supplies a policy whose `delay(Context)` returns null
  to proceed or a delay in seconds. Throwing `Exceptions\Throttled` also defers
  work without consuming failure retries.

`Policies\ExceptionHandler` is a reusable base for handlers delegated to from
`onException`. `Policies\RetryPolicy` may be replaced through configuration.
`Context` exposes workflow/step/attempt identities, failure count, `heartbeat()`
and `isCurrent()` for cooperative long-running work.

Skip counts as concluded and produces no result. A skipped parent skips its
unstarted descendants. Stop/failure cancels dependent unstarted work and activates
applicable recovery roots. Cancellation never interrupts a running action.
Failure propagates immediately to waiting parents, while already-running siblings
finish under their own ownership. Successful recovery is observable as separate
work; it does not rewrite the original failure or reopen cancelled successors.
Unused recovery branches are skipped. Workflow status remains failed if any step
has failure semantics, including when its recovery succeeds.

## Typed inputs and results

Literal scalars, arrays, null, dates and collections use a versioned JSON codec.
Dates restore as `DateTimeImmutable`; collections restore as Support collections.
Register backed enums under `scarlett.enums` using stable aliases. Register DTO or
value-object mappers under `scarlett.payload_mappers`; each mapper implements
`Contracts\PayloadMapper`. The representation returned by a mapper must itself
be encodable. Version your aliases when changing a mapper's representation.

Use `Inputs\Service(Foo::class)` for explicit execution-time container resolution.
Use `Inputs\ModelReference('alias', $id)` with `scarlett.models` for current model
data. A deleted referenced model fails clearly. For snapshots pass an explicit
array/DTO of required attributes. Bare Eloquent models, arbitrary objects, resources
and closures are rejected. Queue payloads contain step/attempt identities, not
business objects. A skipped predecessor cannot fabricate a missing result.

`FromResult` is a top-level named binding to a prerequisite result, parent action,
or rescue target. It does not dynamically evaluate closures or arbitrary expressions.
Combine complex predecessor values in a typed DTO inside a step.

## State extensions

Persisted step states use Spatie model-states. Register concrete classes extending
`States\StepState` under `scarlett.states`, each with a unique stable `$name`.
Declare phase, dependency satisfaction, recovery activation, and subtree skipping
semantics. Add explicit allowed transition pairs under `scarlett.transitions`;
a third entry may name a Spatie transition guard class. Configurations remain
compatible with Laravel configuration caching.

For example, extend `States\Completed` with `$name = 'approved'`, register it,
allow `Running -> Approved`, and return `Decision::complete($result, Approved::class)`
from an acceptance hook. For parents also allow `WaitingForChildren -> Approved`.
The same semantic methods govern ordering, aggregation, recovery and retention.
A new operational phase needs an implementation and tests, not just a state label.
Settled states cannot be reopened. Never mutate stored engine states directly;
use the lifecycle and engine controls so ownership and delivery guarantees apply.

## Recovery and operations

```php
if (Scarlett::stalled()) {
    Scarlett::resume();
}
Scarlett::cooldown(); // hold new workflows; drain started work
Scarlett::reopen();   // admit held workflows
Scarlett::cancel($stepId); // true only for work that has not started
```

Schedule `scarlett:recover` at an interval appropriate to your recovery needs.
It is not required for healthy normal progression. Running attempts expire at
`lease_seconds`; heartbeats extend a current lease. Queued deliveries can be
replayed after `delivery_retry_seconds`. Lost attempts consume the relevant
failure or verification budget. Replays of queued deliveries keep the same token.

The default retry budget is three attempts, with configurable backoff. Worker
`timeout` must be shorter than the execution lease and queue `retry_after` must
exceed the timeout (defaults: timeout 240s, lease 300s; use retry_after >240s).
Use a real delayed queue for retry/backoff. The sync queue is for immediate local
examples; it cannot provide delayed worker execution.

History is stored in `scarlett_history`; records include transitions, attempt,
time and reason. Step records retain the last error and counters. Use
`History::archive($workflowId)` for settled history, then
`History::purge($before)` with an explicit retention cutoff. Active workflows
are protected. No automatic retention age is imposed.

## Durability boundary

A workflow row lock serializes claims, outcomes, cancellation and recovery.
An attempt token fences stale workers. Outcome persistence, eligibility and
outbox intent share a transaction. Delivery starts after commit, with a short
publishing lease to suppress overlapping publishers. A crash or transport failure
leaves recoverable intent. Duplicate jobs cannot claim the same attempt twice.

External actions are **at least once**, not exactly once. A process can crash after
an external effect but before saving its result. Consumers must use business
idempotency keys where repeating that effect matters. Lease expiry cannot stop an
external call already in progress; it prevents that old worker from overwriting
new engine state. Constructors should only bind data, not perform external effects.

Treat the storage models and execution internals as engine-owned. Only data from
trusted workflow definitions should reach the engine; never accept arbitrary step
classes or service bindings from an HTTP request.

## Tests

```sh
composer install
./run-pest-tia.sh --filtered --testsuite=Scarlett
./run-pest-tia.sh --fresh
```

The full suite includes independent PHP workers and actual MySQL lock contention.
Create a dedicated `scarlett_collision_tests` database first. Server configuration
uses `SCARLETT_TEST_HOST`, `SCARLETT_TEST_PORT`, `SCARLETT_TEST_USER`, and
`SCARLETT_TEST_PASSWORD`. The suite refuses to reset any other database. Its user
needs access to `performance_schema.data_locks` to prove overlap. Never run the
collision suite in parallel against the same test database.

Local runs use Pest 5 TIA with Herd coverage or PCOV/Xdebug. Explicit file selections
are executed directly by Pest. CI runs exhaustive non-TIA tests. The package has
no browser test dependency. `composer format` applies Laravel Pint.

Deferred: dynamic children, general scheduled execution, and prefixed execution
scopes. There is zero Step Dispatcher API/schema compatibility. See
[migration-from-step-dispatcher.md](migration-from-step-dispatcher.md).
