# Current state — 2026-11-14

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
