# Migrating from Step Dispatcher to Scarlett

This is the implementation handoff for consumer migrations, including Freshsites.
Scarlett is a fresh integration: no old classes, hooks, tables, state aliases or
serialized jobs are compatible. Do not point Scarlett at dispatcher tables.

## Install and upgrade the package

Use the local Composer path repository during migration:
`../packages/brunocfalcao/scarlett` from a project under `Herd/`. Require
`brunocfalcao/scarlett:dev-main` with symlinking enabled. This preparation may be
present locally before publication; a remote `dev-main` install does not include
uncommitted local changes. Keep both dependencies only while separately owned old
workflows drain. Never execute one workflow with both engines.

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
Fresh installations run all five; existing installations run only missing files. Repeated `migrate` is a
no-op. Legacy Scarlett rows with no execution snapshot use configured defaults.
New rows retain their admitted execution settings across deployments.

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

## Public API mapping

| Existing integration | Scarlett replacement |
| --- | --- |
| `BaseStepJob`, `compute()` | `Scarlett\Step`, public typed `handle()` |
| Constructor arguments | Named `Node::inputs`; constructor and action may both use them |
| Execution identity / attempt snapshot | Type-hint `Execution\Context` in the constructor or `handle`; the engine supplies it after claim, never through persisted inputs |
| `Step::create`, indexes and blocks | Complete `Definition\Workflow` of `Node` definitions; `Engine::start()` returns the workflow record |
| Canonical step identity | `Node::key`; stable within the workflow |
| Sequential / parallel ordering | Increasing `stage` values / same-stage siblings |
| Parent and children | `Node::parent`; all children declared before admission |
| Prior outputs | `Inputs\FromResult`, resolved only when eligible; do not construct successor jobs upfront |
| Services / DTOs / enums / models | `Inputs\Service`, registered payload mappers, configured enum aliases, `ModelReference`; explicit snapshots remain arrays/DTOs |
| `startOrSkip` / other preconditions | `before(Context): ?Decision` |
| `dispatch_after` | `Node::notBefore`, an absolute `DateTimeInterface`; prerequisites must also succeed |
| Per-step timeout, retries and backoff | `Definition\ExecutionOptions` on `Node::execution` |
| `retryException` / ignored exceptions | `onException(Throwable, Context): Decision`; use retry, fail, skip, stop or complete deliberately |
| `resolveException` and terminal business updates | Predeclared `rescues` nodes for durable final-failure work, including exhausted attempts and worker crashes |
| Confirmation | `verify(Context, mixed $result): bool`; retries only verification after saving the business result |
| Throttling | `Policies\Throttle`, `Decision::throttle()` or `Exceptions\Throttled`; no failure-budget consumption |
| Obsolete-worker write guard | `Context::whileCurrent(callable): bool` for database writes; retain consumer ownership checks inside it |
| Withdraw queued work | `Engine::cancel($stepId)` |
| Revoke active step / complete workflow | `cancel($stepId, includingRunning: true)` / `cancelWorkflow($workflowId)` |
| Polymorphic relation / workflow category | `Workflow(subjectType:, subjectId:, metadata:)`; query `WorkflowRecord::forSubject($type, $id)` |
| Step timestamps / diagnostic state | `started_at` (latest claim), `finished_at` (settlement), `scarlett_history` transitions, `error`, `failures`, `verifications` |
| Archive / retention / specific deletion | `History::archive($id)`, `purge($before)`, `delete($id)`; all require settled work |
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
check that its current workflow is still `Context::workflowId`. The package
serializes this callback with claims, recovery and cancellation using the workflow
row lock. False means the callback was not run. Exceptions roll its writes back.
This guard does not prove application-specific authority or validate a business
result. All participating data must use the same database connection.

Use a consistent lock order: Scarlett workflow lock, then consumer rows. Migration
must review rescan, deletion and send paths together; do not transplant the old
site-then-step lock order into guarded callbacks. When several workflows need to
be revoked, lock them in a deterministic order before taking consumer locks. A
transaction may be retried after a deadlock, so guard callbacks must contain only
transactional database work. External effects need their own idempotency/claims.

`History::delete` permits targeted cleanup only after settlement/revocation. It
removes that workflow's deliveries, transitions and steps in foreign-key order,
without cascades. External files and business rows remain the application's job.

## Error semantics that must be preserved

`onException` runs when the constructed action/precheck/throttle throws. Input resolution or construction errors use `scarlett.input_exception_handler`: temporary failures retry within the existing budget, while `scarlett.input_terminal_exceptions` keeps invalid/deleted inputs terminal. Preparation after action persistence uses the verification budget. Keep constructors free of external side effects. Result-saving errors fail without repeating the action. Result restoration and verification errors use the verification budget, and every verification receives the decoded saved result. It is not
a guaranteed final-failure callback: a killed process never runs it, and the engine
can exhaust the retry or verification budget after it returns. Put required
terminal business updates in predefined `rescues` nodes. A resolver can read its
failed owner's `StepRecord` by workflow ID and stable key, including the persisted
error and counters. The original Throwable is not serialized. Resolvers need their
own idempotency and retry policy.

A successful resolver does not erase the original failure or reopen cancelled
successors. If a recoverable business condition should allow normal progression,
handle it as an accepted outcome (`Decision::complete($fallback)`), not a failed
step followed by a rescue. Skipping satisfies ordering but supplies no result.
A consumer may distinguish workflow technical failure from its business status
(e.g. a design held for human review).

## Freshsites migration checklist for Claude

Freshsites was inspected at Laravel 13.42.0 / Step Dispatcher 1.21.7. Recheck its
current source before edits. This package preparation does not migrate that app.

1. **Reuse the existing admin entry points.** “Scan this website” calls
   `SiteController::store`; “Scan again” calls `SiteActionController::rescan`.
   Both reach `SiteWorkflow::scan` without discovery delay. No new button needed.
   The random “Fetch a new one” button uses discovery and is a different path.
2. **Predefine the graph.** Move the fixed `ScanPipelineStep::STAGES` definition
   to the workflow factory: fetch, judge, strategy, images, design, check, fix,
   recheck, pitch. Keep the root's business action (mark scanning); remove its
   runtime child inserts. Preserve `forceDesign` on judge/pitch. Predeclare
   appropriate final-failure resolvers. No dynamic-child feature is necessary.
3. **Keep reads late.** Pass site/design identifiers and reload current state at
   execution. Preserve DTOs such as `FetchedPage` in the services; use registered
   mappers only when persisting objects as engine inputs/results.
4. **Translate the base class carefully.** `BaseSiteStep::shouldRun` becomes a
   precheck. Keep the site's current-workflow check, and replace tick/retry
   snapshots with `Context` plus `whileCurrent`. Remove direct overrides of
   dispatcher finalization, retry and failure internals. Rewrite rescan/deletion
   lock order with those guards. Do not remove stale-result protections.
5. **Keep discovery as consumer orchestration.** Each candidate remains a separate
   predefined workflow. Preserve `checks_at_once`, search budgets, candidate
   replacement and the global start reservation lock. Put the reserved timestamp
   on the root's `notBefore`. Read reservations from consumer data or Scarlett
   roots joined through workflow subjects; do not derive them from `created_at`
   alone. Existing configured 120-second spacing and 10-second replacement delay
   belong in Freshsites configuration, never in Scarlett. Preserve transactional
   accounting plus durable `ContinueDiscoveryStep` workflows. A new independent
   workflow is permitted; appending nodes to a running graph is not.
6. **Carry execution policies explicitly.** Inventory every class's timeout and
   retry/backoff override; current timeouts range through 1,620 seconds. Put these
   values in consumer configuration and build `ExecutionOptions`. Keep the image
   step's last-attempt fallback, transient/permanent classification, provider
   requested waits, design failure reasons and design-check holds. Final failure
   must also update business state after a crash or exhausted verification.
7. **Preserve email protections.** Pitch/reminder remain independent workflows
   associated with the site; they do not replace its scan workflow ID. Retain
   address-level claims, one-send rules, never-contact/opt-out checks, sending
   windows, rechecks after image preparation and uncertain-handover treatment.
   Use a single business attempt where sending must not retry, with durable
   failure handling that understands persisted send claims. Scarlett does not
   guarantee exactly-once mail or retain a killed worker's in-memory flags.
   Keep `sites:send-emails` and its business schedule. It already queues only due
   messages; engine scheduling does not replace this application policy.
8. **Rewrite projections and cleanup.** Update `WorkProgress`, `AdminActivity`,
   `SitePage`, `Site` send eligibility, `EmailPacer`, `DiscoveryRunner` and
   `SiteDeleter`. Associate all independent site workflows via subject IDs, and
   distinguish scan/revision/pitch/reminder in metadata. Map aliases explicitly:
   old Dispatched becomes Queued; WaitingForChildren is a live parent, not a
   finished step. For activity duration use step timestamps/history. Preserve
   deletion refusal during a mail handover and delayed file cleanup. Do not
   silently omit archived workflows from site deletion.
9. **Replace operations after cutover.** Replace `freshsites:dispatch-daemon`,
   `steps:recover-stale`, archive/purge commands and their process-manager wiring
   with Scarlett workers/recovery and consumer retention commands. The archived
   marker keeps data in Scarlett tables; filter it deliberately in live listings.
   Preserve all business schedules and old-engine history until retention allows
   removal. Distinguish old/new workflow IDs during staged migration; keep tests
   local/staging until both paths are proven. No live row conversion.
10. **Port acceptance tests before production.** Replace `Tests\Concerns\RunsSteps`
    ticking with Scarlett progression. Cover the full manual scan, skipped site,
    permanent and transient errors, image fallback, quality-check hold, rescan,
    obsolete workers, deleted sites, independent sends, duplicate/uncertain mail,
    opt-out during preparation, discovery spacing/capacity/replacement/accounting,
    Full auto, progress/activity, retention and cleanup. Use mocked external AI,
    HTTP and mail in business tests plus real queue/MySQL collision tests for
    transaction boundaries. Package tests do not prove Freshsites business parity.

Start with the existing manual scan in local/staging, then discovery and email
paths progressively. Changing the shared scan service also affects discovery:
route the pilot explicitly or prove both callers before switching that service.
Keep a rollback plan based on draining/restarting the appropriate engine, never
replaying completed external actions. Actual migration and production cutover
remain separate work for the consumer implementer.

Still deferred: runtime addition of children and prefix-isolated engine tables.

### Package hardening before migration

Run ordinary `php artisan migrate` after updating the local/package dependency; the provider discovers additive key-comparison and scheduled-date migrations. Preserve exact step identities (maximum `Node::MAX_KEY_LENGTH` characters). Payload version 2 reads existing version 1 values and preserves timezone names for newly stored dates. Use supported queue delays; early deliveries are released until due. Set the recovery delivery window above expected queue latency.

Result persistence rejection fails the step without rerunning a completed action. Every verification receives the restored durable result, with an ownership recheck after decoding. Progression failures/cancellations now expose their reason in the persisted step error as well as history. Literal scalar/array signature errors fail before admission; future results and services remain late-bound.


## Freshsites readiness checkpoint — 2026-11-13

**Ready to begin a local/staging migration; production cutover is not yet proven.**
Rechecked Freshsites at `62d13a91451abaa49d59132954e9b5d688c98f31`, with Laravel
13.42.0 and Step Dispatcher 1.21.7 installed. Freshsites remained unchanged.
Its repository README, current callers and acceptance tests were the source of
truth; no separate shared Freshsites documentation directory was present.

| Freshsites behavior | Scarlett support available now | Migration acceptance still required |
| --- | --- | --- |
| Manual scan, nine ordered stages, force-design and revision | Predeclared parent/child graph, stages, typed late construction, prechecks | Port existing manual-scan tests, skips, forced review, design revision and quality holds |
| Rescan and deletion during execution | Workflow cancellation, attempt ownership, guarded database writes, settled-history deletion | Prove obsolete workers cannot write; preserve refusal during mail handover and delayed file cleanup |
| Transient errors and image fallback | Per-node budgets/backoff/timeouts, final-attempt checks, preparation recovery, durable rescue nodes | Preserve each service's failure classification, normalized business reason, final-attempt image fallback and crash handling |
| Discovery capacity and replacement | Delayed roots, subjects/metadata and independently admitted continuation workflows | Preserve atomic accounting, capacity, cross-run spacing, replacement delay and replay safety |
| Independent pitches and reminders | Separate workflows, one-attempt execution settings and durable failure handling | Preserve address claims, opt-outs, sending windows and uncertain handover; Scarlett cannot supply exactly-once mail |
| Admin progress, activity and operations | Stable keys, workflow metadata, state history, error fields, timestamps and recovery command | Translate projections/retention and replace daemon wiring; do not infer business status from engine success alone |
| Consumer database upgrades | Provider-loaded additive migrations; repeat migration and data-preserving upgrade checks | Run the normal migrate command in the target environment before new workers start |

Focused package readiness verification: **35 tests / 189 assertions passed** across
`ConsumerReadinessTest`, `PackageMigrationsTest`, and the MySQL `MigrationsTest`,
`OwnershipTest`, and `QueueTest`. This includes real workers and concurrent
ownership checks. It does not execute migrated Freshsites classes or certify
Freshsites business parity. The latest broader affected package/host check remains
119 package tests plus 14 host tests, recorded separately in the testing ledger.

Implementation order and gates:

1. Start with the existing manual scan and force-design/revision paths in local or
   staging. Route new pilot workflows deliberately and persist/resolve which engine
   owns each workflow. The shared `SiteWorkflow::scan` also serves discovery, so a
   global replacement is not a manual-only pilot. Never hand a workflow to both engines.
2. Preserve atomic admission: create the Scarlett workflow and save its identifier
   on the consumer row in the same database transaction so publication waits for
   that outer commit. Review the controller transactions too:
   `SiteActionController::startScan` currently updates the site before entering
   `SiteWorkflow::scan`; replacing only the service would retain the old lock order.
   Rework rescan, deletion and guarded writes together, with engine locks before
   existing consumer rows. New discovery candidates and their accounting must
   still commit together with their workflow and continuation intent.
3. Port business failure handling deliberately. Durable rescue nodes cannot inspect
   the original in-memory Throwable or mail flags. Persist the normalized failure
   details and send-claim evidence the resolver needs. Keep the distinction between
   a failed first design, a failed revision that preserves an earlier design, and a
   quality-check failure that holds a design for review.
4. Bring discovery across after its spacing, replacement and accounting tests pass.
   Bring pitch/reminder sending across after duplicate-send, address-sharing,
   opt-out, window-change and uncertain-handover tests pass. Keep external calls
   mocked during business migration checks and add real queue/database acceptance.
5. Use the local path package during development, or pin the reviewed commit on
   `main` once these changes are published. Branch publication does not create a
   version tag. Version selection and the actual
   production worker configuration are separate release gates. Freshsites' local
   configuration currently selects the database queue with `DB_QUEUE_RETRY_AFTER=1700`;
   its longest declared step timeout is 1620 seconds. Carry these values from
   consumer configuration and verify the effective target configuration, lease and
   process-manager shutdown grace before cutover; local configuration is not
   production evidence.

Zero-duration throttles now wait for the configured positive
`scarlett.minimum_throttle_seconds` (default 1), without consuming failure retries.
Explicit positive throttle delays and ordinary zero-second retries remain unchanged.
This does not require a new Freshsites business rule:
Freshsites currently uses retry backoff (at least its base 30 seconds for classified
transient action failures), absolute discovery start times, and due-email scheduling;
it has no explicit zero-delay throttle call to translate.

No additional engine feature was required by this reassessment. Runtime child
addition and prefix-isolated tables remain deferred. Business integration tests,
worker operation and staged cutover are the remaining proof, rather than another
round of speculative package features.
