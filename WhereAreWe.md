# Current state — 2026-11-14

## v2.3.0 labels and invocation diagnostics — 2026-11-14

Workflows and nodes accept optional title, description and canonical labels.
UUIDs and graph keys retain their identity rules. Step signatures are reflected
without business construction; actual constructor/handler arguments are captured
under current-attempt ownership. Existing step classes need no conversion.
Results remain on individual steps, with no workflow-level result payload.

Snapshots describe the latest captured action attempt; verification preserves
that action's arguments. Services/context use descriptors and unencodable values
are explicitly unavailable. Mappers may run during capture and must remain free
of business side effects. See [record shapes and limits](docs/guide.md#describe-workflows-and-inspect-step-invocations).

The eleventh migration adds only nullable labels/diagnostics. All ten published
migrations retain their bytes. Consumers must run ordinary migrations before
updated workers; rollback discards the new diagnostics, preserving prior engine
inputs, results and execution state. No consumer database was migrated here.

Fresh full package verification on PHP 8.5.12:

| Database | Passed tests | Assertions |
| --- | ---: | ---: |
| SQLite | 542 | 4,981 |
| MySQL | 542 | 4,988 |
| PostgreSQL | 542 | 4,986 |

Nineteen metadata cases cover optional labels, identity, late defaults,
context/services/variadics, resolved/mapped/falsey values, retries, skips, stale
attempts, unsupported defaults and data-preserving migration lifecycle.
Each full lane includes the existing real queue and contention checks.
The reference host passes **58 tests / 425 assertions** against the exact package
symlink, using its exhaustive no-Git TIA bootstrap exception. All package lanes
complete without skips. Publication and exact-SHA CI are recorded separately.

Pint, Composer strict validation and local documentation links pass. No configured
static analyzer exists. Browser, provider, consumer-production and throughput
acceptance are not implied. Source publication is separate from deployment;
the [v2.3.0 Release](https://github.com/brunocfalcao/scarlett/releases/tag/v2.3.0)
and canonical implementation record carry publication/CI evidence.

## v2.2.1 recovery and expanded coverage — 2026-11-14

Concurrent SQLite recovery now closes candidate read cursors before taking write
locks. Bounded ID-based batches cover action and terminal-handler candidates;
existing locked eligibility checks, failure snapshots and retry budgets remain.
Four competing-command cases reproduced the lock failure before this fix and
passed afterward. The package adds no schema, dependency or public API change;
all ten published migrations remain byte-identical to v2.2.0.

The candidate includes the complete test expansion since v2.2.0: lifecycle,
encoding, command/configuration, parity, real Redis/database queue, recovery,
cooldown and bounded scale cases. Mixed skipped/successful rescues cover parallel
and sequential groups in both declaration orders. The separate reference host
covers late duplicates in all five terminal states with unchanged persisted
history and no repeated action or terminal hook.

Isolated mutation checks failed all four rescue cases and all five terminal
redelivery cases. A two-second worker-startup delay exposed a Redis test timing
assumption; a shared test clock now proves exact delayed-queue state before and
at eligibility. Naming invalid UTF-8 datasets keeps reporting/TIA metadata valid.
CI provisions Redis 7 and its PHP extension in every PHP/database lane.

Fresh full package runs on the final PHP candidate:

| Database | Passed tests | Assertions |
| --- | ---: | ---: |
| SQLite | 523 | 4,824 |
| MySQL | 523 | 4,831 |
| PostgreSQL | 523 | 4,829 |

Every lane includes feature, migration, real queue and collision tests, with no
skipped cases. Package Pint, Composer strict validation, unchanged tested-PHP
hashes, migration immutability, local documentation links and diff checks pass.

The separate host passes **58 tests / 425 assertions** against this exact package path, using its
exhaustive no-Git bootstrap exception. No browser, provider, consumer-production
or capacity acceptance is implied. No consumer deployment or registry submission
is part of this release.

Publication and exact-SHA CI are tracked separately against the
[v2.2.1 Release](https://github.com/brunocfalcao/scarlett/releases/tag/v2.2.1).

## v2.2.0 optional connection release — 2026-11-14

Version 2.2.0 adds opt-in release of Laravel's default database connection before
business actions, verification and durable terminal hooks, and when runners exit.
Queries reconnect normally. Workflow definitions, step signatures, durable state,
lease ownership, retries and parent/child barriers retain their existing contracts.

Enable with `SCARLETT_RELEASE_DATABASE_CONNECTION=true`; the default is false.
`Context::releaseDatabaseConnection(): bool` and
`Execution\ConnectionRelease::release(): bool` allow a second explicit boundary
after request preparation. Known active transactions, persistent connections and
memory/URI SQLite stores remain connected. Other named connections are untouched.
Session-local state and raw SQL transaction control are outside the opt-in contract.
Queue acknowledgement and later queries may reconnect; zero idle worker connections
and offline execution are not promised. See [the complete connection contract](docs/database-connections.md).

There is no schema or dependency change. All ten published migrations retain their
exact bytes. Consumers need no workflow conversion; no consumer configuration,
lockfile, database or deployment was changed. This is source preparation only.

Fresh local full suites ran on PHP 8.5.12 through `./run-pest-tia.sh --fresh`, with
`SCARLETT_RELEASE_DATABASE_CONNECTION=true` and `SCARLETT_TEST_DRIVER` set per lane
(local PostgreSQL user supplied separately):

| Database | Passed tests | Assertions |
| --- | ---: | ---: |
| SQLite | 416 | 4,193 |
| MySQL | 416 | 4,200 |
| PostgreSQL | 416 | 4,198 |

The seventeen new cases pass independently on all three databases (101 assertions
each). They cover PDO release/reconnect, transaction and callback safety, disabled
and ineligible connections, guarded writes/rollback, cancellation, terminal hooks,
verification without action replay, quotas/retries and nested backup results.
The full suites also exercise fresh/upgrade/repeat migrations and existing real
worker collisions. Reference host: **14 tests / 56 assertions** against the exact
package symlink, using its exhaustive no-Git TIA bootstrap exception.

The first MySQL full run was interrupted during investigation of buffered output;
its 192 passed / 224 pending result is not accepted as release proof. Only the
complete repeat is eligible. No defect or source change resulted from that check.

Package Pint, Composer strict validation, PHP syntax, unchanged tested-PHP hashes,
local Markdown links and diff checks pass. No configured static analyzer exists.
Browser, live-provider, consumer-production and throughput acceptance are unverified.
Publication and exact-SHA CI will be verified against the
[v2.2.0 Release](https://github.com/brunocfalcao/scarlett/releases/tag/v2.2.0);
current canonical publication evidence lives in the workspace implementation record.

## v2.1.0 shared throttling source snapshot

Version 2.1.0 adds named rolling-window quotas through `RateLimit`, with an
explicit sharing key across steps/workflows. Permission is acquired atomically
before an action; duplicate checks of the same current attempt cost one admission.
Quota waits do not spend failure retries. Failed API calls use the existing bounded
retry decision and must acquire permission again when due.

This candidate adds the eighth package table and tenth migration. Existing
published migrations are unchanged. Consumers must migrate before enabling the
policy. See [throttling and retries](docs/throttling.md) for configuration, API,
pruning, changing limits and rollout constraints. No consumer or live database
has been changed. Source publication and exact-commit CI are tracked by the
[v2.1.0 Release](https://github.com/brunocfalcao/scarlett/releases/tag/v2.1.0).
This source snapshot does not establish consumer deployment.

Full candidate verification on PHP 8.5.12, using `./run-pest-tia.sh --fresh`
with `SCARLETT_TEST_DRIVER` set per lane (and local PostgreSQL user configured):

| Database | Passed tests | Assertions |
| --- | ---: | ---: |
| SQLite | 399 | 4,092 |
| MySQL | 399 | 4,099 |
| PostgreSQL | 399 | 4,097 |

Each lane ran the entire suite with fresh TIA; none skipped. The 25 new cases are
in `tests/Feature/RateLimitTest.php` and `tests/Collision/RateLimitTest.php`.
They cover shared/independent quotas, exact expiry, retry exhaustion, duplicate
and obsolete attempts, rollback, lease loss, competing workers, old transaction
snapshots, pruning and migration safety. The existing index-migration regression
now names its migration explicitly, so later additive migrations cannot change
what it exercises. All nine published migration files retain their exact bytes.

Reference host: **14 tests / 56 assertions**, against the exact package symlink,
using its exhaustive bootstrap path because no Git TIA baseline exists. Syntax,
Composer strict validation, changed-document links, diff checks and package Pint
pass. No static analysis tool is configured;
browser and provider acceptance remain unverified.
No production or large-scale performance acceptance is claimed.

## Published v2.0.2 baseline

This v2.0.2 source snapshot updates documentation and comments from the v2.0.1
baseline (`537944c1a5cbc1e652958b6de574b89eabbeef63`). It explains the engine's
ownership, lock order, late input restoration, verification, runtime children,
cascading rescues, durable failure hooks, retention and telemetry contracts.
No runtime behavior, public API, dependency or schema changes are included.

Start with the [architecture map](docs/architecture.md),
[consumer guide](docs/guide.md) and
[migration guide](migration-from-step-dispatcher.md). The README remains focused
on examples. The seven tables and nine ordered migrations are unchanged; all
published migration files retain their exact bytes. Existing installations need
no additional migration for this documentation patch.

Local release verification on PHP 8.5.12, with fresh Pest TIA for each driver:

| Database | Passed tests | Assertions |
| --- | ---: | ---: |
| SQLite | 374 | 3,934 |
| MySQL | 374 | 3,941 |
| PostgreSQL | 374 | 3,939 |

The separate reference host passed **14 tests / 56 assertions** against this
package's exact path symlink. Package Pint, Composer strict validation, PHP syntax,
Markdown links/anchors and diff checks passed. Token comparison confirms all 57
changed core PHP files preserve executable code and existing typed annotations.
The release adds no new behavior requiring regression tests. The full existing
suites cover installation/upgrade/repeat/rollback, queue transport, ownership
collisions, nested children, rescues and failure handling.

Source publication and exact-commit CI results are recorded by the immutable
[tag/Release](https://github.com/brunocfalcao/scarlett/releases/tag/v2.0.2).
Local checks do not establish production, browser or 10M-row performance acceptance.
No consumer code, database, dependency lock or deployment was changed by this pass.

Runtime child plans and query indexes remain implemented as documented in v2.0.0
and v2.0.1. Incremental child appending and table-prefix execution scopes remain
deferred. The separate read-only Scarlett UI is not an engine dependency; the
core host's testing UI is still deferred. The 1.x upgrade checklist remains
required when adopting the nullable node priority introduced in 2.0.0.
