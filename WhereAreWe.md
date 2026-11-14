# Current state — 2026-11-14

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
