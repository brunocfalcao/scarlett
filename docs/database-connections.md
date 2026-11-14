# Release database connections between execution checkpoints

Available from **v2.2.0**, disabled by default. Scarlett can release Laravel's
default database connection before long business work, then reconnect when a
query is needed. Workflow definitions and existing step signatures stay unchanged.

## Enable it

With the package's configuration mapping, set:

```dotenv
SCARLETT_RELEASE_DATABASE_CONNECTION=true
```

The corresponding configuration key is `scarlett.release_database_connection`.
If your application already publishes `config/scarlett.php`, add the mapping
without replacing its other settings:

```php
'release_database_connection' => env('SCARLETT_RELEASE_DATABASE_CONNECTION', false),
```

Rebuild configuration caches and restart long-running workers through your normal
deployment procedure. No migration is added in v2.2.0. Turning the setting off and
reloading workers restores retained-connection behavior without changing records.

Enable this only when your steps and shared integrations do not depend on
connection-local state surviving between these boundaries. Temporary tables,
session variables, advisory/session locks, attached SQLite databases and live
cursors are not preserved by reconnecting. Use durable records and ordinary
transactions, or leave this option disabled for those consumers. Scarlett cannot
infer all such dependencies from application code.

## Automatic boundaries

When enabled, both action and durable-failure runners release an eligible default
connection:

- Immediately before `handle()`, after input restoration, construction, throttle,
  precheck and the existing ownership check.
- Immediately before `verify()`, after restoring the saved result and checking
  ownership; verification retries still do not repeat the saved action.
- Immediately before `onFailure()` or `onFailureWithoutInstance()`.
- When the runner exits, including early returns and exceptions.

Claims, quota reservations, results, outcomes and successor delivery intents keep
their existing database transactions. No state is buffered in process memory in
place of durable storage. Heartbeats and `whileCurrent()` reconnect as needed;
lease expiry, cancellation, retries and parent/child barriers remain unchanged.
No release is inserted inside a guarded write or admission transaction.

Scarlett does not globally disconnect unrelated queue jobs. Queue acknowledgement,
database-backed cache/logging, subsequent listeners and other application code may
reconnect after the runner returns. This is not a guarantee of zero connections
while the Laravel worker is idle, and it does not change queue polling.

## Release again after preparing an API request

A query inside `handle()` reconnects. If it loads data before a long API request,
release again at that boundary. The following step fragment uses Laravel's HTTP
client and an application-owned `services.generator.url` configuration value:

```php
use Illuminate\Support\Facades\Http;
use Scarlett\Execution\Context;

public function handle(Context $context, array $request): array
{
    // Finish any database-backed request preparation before this boundary.
    $context->releaseDatabaseConnection();

    return Http::post(config('services.generator.url'), $request)
        ->throw()
        ->json();
}
```

Shared integration code without a Context may call:

```php
app(\Scarlett\Execution\ConnectionRelease::class)->release();
```

Both APIs return `bool` and obey the same opt-in configuration and safety checks.
`true` means Laravel's eligible PDO handles were cleared; `false` means nothing
was released. Neither checks ownership nor grants permission for an external
effect. Keep existing ownership checks, idempotency and error classification.
A query, lazy-loaded relationship, database-backed throttle or logging operation
after this call can reopen the connection before the network wait. Review the
shared integration when eliminating that specific idle connection matters.

## Safety and limits

Only the already-created **default Laravel database connection** is considered.
Other named connections and separately cached connection variants are untouched.
Cleanup never creates a connection just to disconnect it. It skips:

- Laravel transactions, including nesting, and active transactions detected on
  any existing write, read or direct PDO handle.
- Configured persistent PDO connections, or persistent server handles detected
  at runtime.
- In-memory SQLite and SQLite URI connections; only ordinary existing SQLite
  files are eligible. Closing an in-memory connection could destroy its database.
- Unsupported drivers and handles whose transaction/persistence state cannot be
  inspected safely.

An application retaining its own PDO or statement reference can keep the physical
connection alive after Laravel releases its references. Persistent connections
and session-dependent integrations require their own lifecycle decisions.
Use Laravel transactions or `PDO::beginTransaction()`: raw SQL transaction
control is not portably tracked by these guards. Leave the option disabled for
integrations that manage transactions through raw SQL.
The supported test matrix remains SQLite, MySQL and PostgreSQL.

This reduces connections retained between checkpoints, **not query count**.
Opening new connections has a cost; measure your workload before treating it as
a throughput improvement. Short database-heavy steps may benefit from leaving
the setting off.

The database must still be available to claim work, renew leases, verify ownership,
save results and release successors. An external effect followed by a failed
result write remains an uncertain outcome; reconnecting adds no offline mode or
exactly-once guarantee. Recovery and external-effect idempotency remain required.
