# Current state

The public `v1.0.0` release remains unchanged. The current package candidate adds
runtime child plans for staged fanout and nested backups. Scope is this package
only; no consumer code or application databases were changed.

`Decision::children()` atomically admits one closed plan under current-attempt
ownership, inherits routing, optionally forwards a selected child's result, and
queues parent verification after children settle. Actions are not replayed during
verification. Existing cascading rescues and child-before-parent failure hooks
apply. Predefined graphs retain their behavior.

Read [the API and guarantees](docs/runtime-children.md) and the agnostic
[migration guide](migration-from-step-dispatcher.md). The new additive migration
is required before new workers start; rollback refuses active runtime plans.
Incremental appending and table-prefix execution scopes remain deferred.

Full Pest verification passed on 2026-11-14 with PHP 8.5.12:

| Database | Passed tests | Assertions |
| --- | ---: | ---: |
| SQLite 3.45.2 | 373 | 3,830 |
| MySQL 9.7.1 | 373 | 3,837 |
| PostgreSQL 17.10 | 373 | 3,835 |

Each run rebuilt the TIA graph with `./run-pest-tia.sh --fresh`; no skipped tests.
The 43 added cases cover runtime plans, nested effective results, verification,
routing, failure/rescue ordering, migration safety and real worker contention.
Existing custom completion guards remain supported; their regressions were caught
and repaired before these final full runs. Documentation links and diff checks passed.

This candidate is not tagged or published. No consumer acceptance is claimed.
