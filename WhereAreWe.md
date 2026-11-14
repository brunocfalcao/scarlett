# Current state

The `v2.0.0` source release adds
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

The release preparation reran the full MySQL package gate and the reference host
(14 tests / 56 assertions), with Composer strict validation and package Pint.
The immutable tag, GitHub Release and exact-commit Actions results record source
publication. No consumer migration, deployment or acceptance is claimed.

The version is major because `Node::$priority` is now nullable for inheritance.
Read the [1.x upgrade checklist](docs/runtime-children.md#upgrade-from-scarlett-1x)
before updating existing installations.
