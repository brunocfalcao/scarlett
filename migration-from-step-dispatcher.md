# Migrating from Step Dispatcher to Scarlett

Use this guide to migrate a Laravel application from Step Dispatcher to Scarlett.
Scarlett is a fresh integration: no old classes, hooks, tables, state aliases or
serialized jobs are compatible. Do not point Scarlett at dispatcher tables.

## Install and upgrade the package

Follow the [installation guide](README.md#install) and choose the package revision
you will validate. Use the tagged `^2.2` Composer constraint for this API.
Existing Scarlett 1.x consumers should follow the
[upgrade checklist](docs/runtime-children.md#upgrade-from-scarlett-1x). A Composer
path repository with symlinking is optional for local package development; its path depends on your workspace. A remote install
does not include uncommitted local changes. Keep both dependencies only while
separately owned old workflows drain. Never execute one workflow with both engines.

Provider auto-discovery registers the package migration directory. Run the
consumer's ordinary `php artisan migrate` after installation and each package
update. In production use the project's normal approved deployment migration
command. **Do not copy or publish Scarlett migrations into the application.**
Only configuration is publishable (`scarlett-config`). Do not run migrations from
a service provider's boot method, or use `migrate:fresh` to upgrade an application.

Migration filenames are stable identities in Laravel's `migrations` table. The
initial schema and delivery-lease migration remain unchanged. The new additive
`2026_11_13_000002_add_consumer_execution_options.php` adds subject metadata,
execution settings, scheduling and step timestamps. The subsequent additive
`2026_11_13_000003_preserve_exact_step_keys.php` preserves exact MySQL key comparison;
`2026_11_13_000004_expand_scheduled_date_range.php` permits dates beyond 2038.
`2026_11_13_000005_create_subject_slots.php` adds current-workflow slots;
`2026_11_13_000006_create_failure_executions.php` adds durable terminal handlers.
`2026_11_14_000007_add_runtime_children.php` adds durable runtime-child handoff fields.
`2026_11_14_000008_add_query_indexes.php` adds query indexes in v2.0.1; see
[index coverage and large-table rollout](docs/database-indexes.md).
`2026_11_14_000009_add_throttle_reservations.php` adds shared quota storage and
nullable attempt receipts in v2.1.0; see [throttling](docs/throttling.md).
Fresh installations run all ten; existing installations run only missing files. Repeated `migrate` is a
no-op. Legacy Scarlett rows with no execution snapshot use configured defaults.
New rows retain their admitted execution settings across deployments. Runtime
children require `v2.0.0` or later; `v1.0.0` does not contain this API.
The runtime-child migration refuses rollback while any unfinished workflow uses
its handoff fields. Drain work and stop workers before coordinated schema/code rollback.

Pause admission and drain/stop old workers before updating package code and schema;
apply migrations before starting workers that use new columns. No migration sends
jobs, changes consumer business tables, or converts Step Dispatcher records.
Rollback of the execution-options migration removes its new settings/metadata; it preserves
base workflow data but loses those new values. Treat it as a coordinated code/schema
rollback with workers stopped, not a live feature switch. Future schema changes
must be new migrations, never edits of already shipped migration files.
The key migration refuses a rollback when formerly distinct keys collide under the
old collation. The date migration refuses narrowing when stored schedules exceed
the old MySQL TIMESTAMP range. Resolve those records deliberately before rollback;
never discard them to force a schema downgrade.

SQLite, MySQL and PostgreSQL run the full package suite. For concurrent SQLite
workers, use WAL, a suitable busy timeout and `IMMEDIATE` transactions as described
in the [database guide](docs/guide.md#database-configuration). Validate your consumer
on its actual database and queue; package tests do not replace domain acceptance.

## Public API mapping

| Existing integration | Scarlett replacement |
| --- | --- |
| `BaseStepJob`, `compute()` | `Scarlett\Step`, public typed `handle()` |
| Constructor arguments | Named `Node::inputs`; constructor and action may both use them |
| Execution identity / attempt snapshot | Type-hint `Execution\Context` in the constructor or `handle`; the engine supplies it after claim, never through persisted inputs |
| `Step::create`, indexes and blocks | Complete `Definition\Workflow` of `Node` definitions; `Engine::start()` returns the workflow record |
| Canonical step identity | `Node::key`; stable within the workflow |
| Sequential / parallel ordering | Increasing `stage` values / same-stage siblings |
| Parent and children | `Node::parent` for predefined graphs; `Decision::children()` for one atomic runtime plan |
| Prior outputs | `Inputs\FromResult`, resolved only when eligible; do not construct successor jobs upfront |
| Services / DTOs / enums / models | `Inputs\Service`, registered payload mappers, configured enum aliases, `ModelReference`; explicit snapshots remain arrays/DTOs |
| `startOrSkip` / other preconditions | `before(Context): ?Decision` |
| `dispatch_after` | `Node::notBefore`, an absolute `DateTimeInterface`; prerequisites must also succeed |
| Per-step timeout, retries and backoff | Static `Step::executionOptions(): ExecutionOptions`; explicit `Node::execution` wins |
| `retryException` / ignored exceptions | `onException(Throwable, Context): Decision`; use retry, fail, skip, stop or complete deliberately |
| `resolveException` step classes | `Node::rescues`; equal stages run in parallel, later stages sequentially; recovery cascades child before parent |
| Terminal business updates | `onFailure(Context, Failure)` plus static `onFailureWithoutInstance`; hooks also cover worker loss |
| Replace the current workflow for a subject and slot | `replace(SubjectSlot, callable): ?WorkflowRecord`; callback veto rolls back cancellation and consumer writes |
| Delete a subject and every workflow lane | `deleteSubject(Subject, callable, guardSubjects: [])`; pre-revocation veto, atomic cleanup and protected related subjects |
| Current workflow for progress screens | `current(SubjectSlot): ?string`; no mirrored consumer column required |
| Current workflows for list screens | `currentForSubjects(string $subjectType, array $subjectIds, string $slotName): array`; one query returning `subjectId => workflowId` |
| Confirmation | `verify(Context, mixed $result): bool`; retries only verification after saving the business result |
| Throttling | `Policies\Throttle`, `Decision::throttle()` or `Exceptions\Throttled`; no failure-budget consumption |
| Obsolete-worker write guard | `Context::whileCurrent(callable): bool` for database writes; retain consumer ownership checks inside it |
| Withdraw queued work | `Engine::cancel($stepId)` |
| Revoke active step / complete workflow | `cancel($stepId, includingRunning: true)` / `cancelWorkflow($workflowId)` |
| Polymorphic relation / workflow category | `Workflow(subjectType:, subjectId:, metadata:)`; query `WorkflowRecord::forSubject($type, $id)` |
| Step timestamps / diagnostic state | `started_at` (latest claim), `finished_at` (settlement), `scarlett_history` transitions, `error`, `failures`, `verifications` |
| Archive / retention / specific deletion | `History::archive($id)`, `purge($before)`, `delete($id)`; settled-only by default; subject deletion explicitly revokes unfinished work |
| Normal dispatch daemon | Remove after cutover; outcomes dispatch successors immediately |
| Stalled-work scheduler | `scarlett:recover`, or `Engine::stalled()` / `resume()` |

Inputs must explicitly bind business parameters. A parameter typed exactly as
`Context` is engine-provided; do not supply it in `inputs`. Context injection works
in constructors and `handle`, with the same claimed context. Constructors should
only bind data. Arbitrary container autowiring and serialized closures are not
supported. Queue envelopes contain identifiers and scalar execution settings.

## Execution settings and scheduling

Build `ExecutionOptions` from the consumer's configuration. Supported overrides:
`workerTimeout`, `leaseSeconds`, `attempts`, `backoffSeconds`,
`verificationAttempts`, `verificationBackoffSeconds`. Omitted settings use Scarlett
configuration at workflow admission, and all effective values are persisted.
Override static `Step::executionOptions()` on a shared base or individual class.
An explicit node object replaces class defaults entirely; its omitted fields use
package configuration. No business step is instantiated to obtain settings.
Attempts means total failed business executions allowed, including the first;
verify the old engine's counter convention before translating a value. Verification
has a separate budget. Explicit `Decision::retry($seconds)` overrides backoff for
that decision. `Context::canRetry()` reports whether a further business attempt
remains after this attempt fails; use it for final-attempt fallback behavior.
A custom configured retry policy receives the step as an optional third argument
in `permits` and `delay`; preserve the base method signatures when extending it.

Set `workerTimeout < leaseSeconds`. The queue connection's `retry_after` (or
transport visibility timeout) must exceed the longest job timeout with a margin.
Configure process-manager shutdown grace and outbound request timeouts consistently.
Heartbeats extend ownership, not the process's execution timeout. The package does
not change a consumer's queue configuration. Laravel's job timeout takes precedence
over its worker CLI default. See [Laravel queues](https://laravel.com/framework/docs/13.x/queues).

`notBefore` is an earliest execution time, not an exact deadline. The queue and
available worker capacity determine actual start. Put it on a parent/root to delay
a whole predefined subtree. Dependencies and cooldown still apply; a past time
runs as soon as eligible. Use an asynchronous queue supporting the required delay.
The synchronous driver does not wait for future jobs; due work would require
recovery to redeliver. Transport delay limits still apply. Recurrence and calendar
rules remain application scheduler responsibilities, not engine polling.

## Cancellation and safe business writes

Cancellation revokes execution authority. It does not kill PHP, interrupt an HTTP
call, or undo an external effect already sent. A revoked worker cannot save engine
results, retry, heartbeat or progress. `cancelWorkflow` revokes all unfinished
nodes in one transaction, including pending recovery branches. Finished nodes
remain unchanged. Cancellation does not activate failure resolvers.

For business data, make the external call outside locks, then persist through
`Context::whileCurrent`. Inside its callback, lock/reload the consumer row and
recheck application-specific authority and eligibility. For integrations using a
current-workflow slot, read its pointer with `current(SubjectSlot)` rather than
maintaining a duplicate consumer column. The package serializes this callback
with claims, recovery and cancellation using the workflow
row lock. False means the callback was not run. Exceptions roll its writes back.
This guard does not prove application-specific authority or validate a business
result. All participating data must use the same database connection.

For ordinary guarded writes, acquire the Scarlett workflow lock before consumer
rows. Review replacement, deletion and follow-up admission together; do not carry
over consumer-row-first lock ordering. When a write also calls `start()`, wrap the
whole operation with `Scarlett::withAdmission()` before entering `whileCurrent`
or taking consumer locks. The order is admission, owning workflow, then consumer
rows. Returning `false`/`null` from `withAdmission` does not roll back; exceptions do.
Use the preparation callbacks of `replace()` and `deleteSubject()` for their own
locking and veto rules. Call them before acquiring workflow or consumer locks;
do not call deletion, cooldown or reopening inside `withAdmission()`.

Callbacks must be short, replay-safe database work on the same connection.
External effects need their own idempotency/claims and must stay outside locks.
See [atomic admission and lock ordering](docs/consumer-lifecycle.md#admit-follow-up-work-atomically-with-consumer-writes).

`History::delete` permits targeted cleanup only after settlement by default.
Explicit `includingRunning: true` also revokes unfinished work by removing its
records. It removes failure handlers, deliveries, transitions and steps in
foreign-key order, without cascades. External files and business rows remain the
application's job.

## Error semantics that must be preserved

`onException` runs when the constructed action/precheck/throttle throws. Input
resolution or construction errors use `scarlett.input_exception_handler`:
temporary failures retry within the existing budget, while
`scarlett.input_terminal_exceptions` keeps invalid/deleted inputs terminal.
Preparation after action persistence uses the verification budget. Keep
constructors free of external side effects. Result-saving errors fail without
repeating the action. Result restoration and verification errors use the
verification budget, and every verification receives the decoded saved result.

`onException` is not a guaranteed final-failure callback: a killed process never
runs it, and the engine
can exhaust the retry or verification budget after it returns. Put required
terminal business updates in `onFailure(Context, Failure)` and implement static
`onFailureWithoutInstance` using persisted scalar identifiers when inputs cannot
be restored. Use `Decision::fail($reason)` / `stop($reason)` for normalized text.
The hook receives a typed cause, stable failure ID and saved reason; the original
Throwable is not serialized. One durable record has recoverable attempts, so
business effects must be idempotent. Children settle their hooks before parents;
parents can ignore `FailureCause::ChildFailed` when the child owns the business
outcome. Keep rescues for separate recovery workflows, without duplicating effects.
See [the complete API and lifecycle contract](docs/consumer-lifecycle.md).

For cascading resolver blocks, attach recovery nodes to the failing node using
`rescues`. Nodes targeting the same key share a recovery group inside the same
workflow; no separate block UUID is needed. Equal stages run in parallel and
later stages await all earlier stages.

The order is: child fails/stops, its rescue branches settle, parent becomes
`failed`, then the parent's rescues start. Repeat upward. A waiting parent remains
`waitingforchildren` while lower recovery runs, including nested resolver children,
rescues of failed resolvers, throttles and retries. Unstarted ordinary siblings
are cancelled; already-running siblings and their recovery must settle too.
An ancestor's later ordinary stages remain blocked until its outcome is known.
Resolver exhaustion does not prevent propagation once all lower branches settle.
Durable failure hooks retain separate child-before-parent ordering; rescues do
not wait for hooks to complete.

A successful resolver does not erase the original failure or reopen cancelled
successors. If a recoverable business condition should allow normal progression,
handle it as an accepted outcome (`Decision::complete($fallback)`), not a failed
step followed by a rescue. Skipping satisfies ordering but supplies no result.
A consumer may distinguish workflow technical failure from its business status
(for example, an outcome requiring human review).

## Migration checklist

1. **Inventory entry points and behavior.** Trace controllers, commands,
   schedulers and jobs that create work. Record the graph, business side effects,
   ownership checks, retries, timeouts and cleanup rules. Preserve existing product
   behavior; changing engines does not require new user actions or business rules.
2. **Translate graphs and runtime handoffs.** Use nodes and stages for fixed
   indexes, blocks and parent relationships. For data-dependent work, return
   `Decision::children($nodes, result: $summary)` from the running step. For a
   replacement/backup child, use `resultFrom: 'backup'`; later steps can still read
   the original parent's effective result. Nested children can hand off their own
   plans. Remove consumer-side child-block deduplication and result-copying logic:
   Scarlett owns those guarantees. Read the [runtime-child contract](docs/runtime-children.md)
   before porting: one closed plan, local references, inherited routing, and parent
   verification after children. Independent workflows do not replace awaited children.
3. **Keep inputs late.** Pass identifiers, explicit bindings and supported
   snapshots. Reload current records at execution. Register mappers for objects
   persisted as inputs/results; do not instantiate successors or resolve their
   predecessor-dependent inputs while building the graph.
4. **Translate step behavior.** Port preconditions, actions, verification and
   exception decisions to the public hooks. Remove overrides of dispatcher
   internals. Carry class execution settings through `executionOptions()` and use
   node options for workflow-specific overrides. Preserve fallback outcomes and
   the distinction between action retries, verification retries and throttling.
5. **Preserve terminal effects.** Move required final-failure updates to
   `onFailure` and provide `onFailureWithoutInstance` when deleted or unavailable
   inputs would prevent construction. Use the stable failure ID for idempotency.
   Avoid duplicate parent effects when `FailureCause::ChildFailed` identifies a
   child-owned outcome. Persist any evidence needed after a worker crash.
6. **Model subject ownership.** Associate related workflows through subject type
   and ID. Use a named `SubjectSlot` only for workflows that supersede each other;
   unrelated lanes remain independent. Replace through `replace($slot, $prepare)`:
   return a definition to approve, or `null`/`false` to preserve the previous work
   and roll back callback writes. Use `current()` for one subject and
   `currentForSubjects()` for list screens.
7. **Review transactional writes and deletion.** Use `whileCurrent` to reject
   stale attempts. Wrap writes that admit follow-up workflows in `withAdmission`
   before taking locks. Use `deleteSubject()` to revoke all target lanes and
   remove their history, including archives. Its callback runs after engine locks
   but before revocation: return `true` to approve or `null`/`false` to veto.
   Pass related subjects as guards when your business rule depends on shared
   resources, and recheck those relationships under consumer locks. Guarded
   subjects remain unchanged. Defer external cleanup until commit. Producers must
   still reject a deleted subject; deletion is not a permanent engine ban.
8. **Preserve external-effect protections.** Keep consumer idempotency keys,
   resource claims, permissions and eligibility checks. A worker can crash after
   an external action but before saving its result. Scarlett cannot guarantee
   exactly-once effects or recover a killed worker's in-memory flags. Preserve
   the application's treatment of uncertain outcomes.
9. **Update projections and operations.** Translate state names deliberately:
   `Dispatched` becomes `Queued`; `WaitingForChildren` remains active. Use workflow
   status, step timestamps and history for progress. Retain business schedules,
   capacity limits and accounting in the application. Replace normal dispatcher
   ticks with queue workers and scheduled recovery after old work drains.
10. **Prove the consumer integration.** Port acceptance tests to outcome-driven
    progression. Package tests prove engine behavior, not your application's
    business parity. Verify the scenarios below before switching production.

## Recovery and retention

Schedule `scarlett:recover` to repair interrupted work and lost deliveries; it
is not required for normal progression. Choose the recovery interval and
`delivery_retry_seconds` for your queue latency. Early deliveries wait until due
without consuming the business retry budget.

Replace old retention commands with `scarlett:archive` and `scarlett:purge`.
Supply exactly one age option, `--minutes=N` or `--days=N`, where `N` is a
non-negative integer chosen by the application. There is no default age.
Both accept `--batch=N` (positive integer, default `100`); this limits query size,
not the total records processed.

Archive uses workflow finish time; purge uses the original archive time, with
strict older-than cutoffs and locked settlement checks. Live work and unfinished
failure handlers are protected. Repeated and overlapping runs are safe. Scarlett
marks archives in place; it does not move them to a separate archive table.
Filter archived workflows explicitly in live listings. The application owns the
schedule and retention policy; Scarlett registers no schedule.

Zero-duration throttles use the positive `scarlett.minimum_throttle_seconds`
(default `1`) without consuming failure retries. Positive throttle delays and
ordinary zero-second retries retain their requested meaning.

## Acceptance checks and progressive cutover

| Area | Consumer verification |
| --- | --- |
| Graph and data flow | Sequential/parallel barriers, nesting, late construction, typed inputs and predecessor results |
| Outcomes | Success, skip, stop, cancellation, bottom-up cascading rescues, parallel/sequential resolver stages and failed-parent propagation |
| Retries and failures | Temporary/permanent errors, final-attempt fallback, verification without action replay, lost workers and missing inputs |
| Ownership | Replacement veto and success, stale workers, cancellation during execution and concurrent subject deletion |
| Transactions | Consumer writes and follow-up admission commit together; rollback publishes no work; lock ordering holds under contention |
| External effects | Duplicate delivery, uncertain outcomes, idempotency and eligibility changes while work runs |
| Operations | Scheduled starts, cooldown, recovery, progress screens, archival, purge and subject cleanup |
| Package upgrades | Fresh installation, data-preserving upgrade and repeated migration against the candidate revision |

Use controlled external-service substitutes for business tests and real queue/
database tests for concurrency boundaries. Verify target queue timeouts, leases,
process-manager shutdown grace and scheduler wiring; local configuration alone
is not production evidence.

Start with one explicitly routed workflow in local or staging, then expand after
its acceptance checks pass. Review every caller of shared orchestration code
before switching it. Track which engine owns each workflow throughout the
transition. Preserve old history until its retention policy permits removal.
There is no automatic conversion of live Step Dispatcher records or queued jobs.

Keep rollback based on routing new work and draining the appropriate workers,
not replaying already completed external actions. Choose a reviewed package
revision and apply its migrations before starting updated workers.

## Boundaries to account for

- Incremental appending to an admitted child plan and prefix-isolated engine tables remain deferred. Atomic runtime child plans are supported; see [the API and limits](docs/runtime-children.md).
- Step identities are exact and limited by `Node::MAX_KEY_LENGTH`; do not normalize
  case or merge distinct keys during migration.
- Payload version 2 reads version 1 values and preserves named timezones for newly
  stored dates. Persisted representation changes need deliberate mapper versioning.
- Result persistence rejection fails the step without replaying its action.
  Verification restores the durable result and rechecks ownership after decoding.
- Literal scalar/array signature mismatches fail before admission. Future results
  and service bindings remain late-bound and can fail during input restoration.

See [the lifecycle API](docs/consumer-lifecycle.md) for exact signatures, veto
semantics, failure causes and transaction examples, and [the full guide](docs/guide.md)
for configuration and operations.

## Optional shared rate limits (v2.1.0)

Run ordinary package migrations before enabling `RateLimit`. Configure named
`max_requests`/`per_seconds` policies, then return a policy and explicit sharing key
from each applicable step's `throttle()` hook. Share an account key across workflows
when they consume the same provider quota. This does not count API errors as
throttle waits: classify unsuccessful calls with `Decision::retry($seconds)` to
retain bounded retries. Each retry must acquire admission again.

See [the API, examples, migration and rollout rules](docs/throttling.md). Keep AI
calls out of constructors, use one intended call per admitted action, and preserve
remote-effect idempotency. Existing custom throttlers need no conversion.

## Optional connection release (v2.2.0)

No schema migration or workflow/step signature conversion is required for this
feature. Set `scarlett.release_database_connection` through the documented
`SCARLETT_RELEASE_DATABASE_CONNECTION` mapping to opt in. Existing consumers
retain their current connection lifetime by default.

Review session-local database state and live cursors before enabling. If an action
queries the database before an API call, use `Context::releaseDatabaseConnection(): bool`
or the shared `Execution\ConnectionRelease::release(): bool` helper after request
preparation. Ordinary queries reconnect; guarded writes retain their transaction.
Do not replace `whileCurrent()` with a release call or suppress heartbeat needs.

Consumer acceptance must cover actual database/queue routing, temporary/session
state, lazy input queries, long calls, cancellation, verification retries and
terminal handlers with the option enabled. See [the complete connection contract](docs/database-connections.md).

## Concurrent recovery fix (v2.2.1)

Existing Scarlett consumers need no schema, configuration or workflow conversion.
Recovery now closes candidate read cursors before taking write locks, allowing
competing SQLite recovery commands to repair expired leases and lost deliveries
for both actions and terminal hooks. Reload workers through the consumer's normal
release process. Retry budgets, eligibility, attempt fencing and failure snapshots
retain their existing meaning. The wider package/host suites do not replace the
consumer acceptance matrix above.
