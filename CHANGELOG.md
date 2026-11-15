# Changelog

## 2.3.1 — 2026-11-15

### Tests

- Verify that a running parent can create a child and grandchild after enabling cooldown, and that the entire subtree completes without reopening admission. Assert queued deliveries, exact action calls and the forwarded result.
- Verify that an unrelated workflow created during the same cooldown remains held, with no queued delivery or execution, even when its runner is invoked directly.

### Upgrade

- Regression coverage and documentation only; no runtime, public API, configuration, dependency or schema changes. All eleven published migrations remain unchanged. Upgrading from 2.3.0 requires no new migration or step conversion; earlier upgrades still require their existing migrations.

## 2.3.0 — 2026-11-14

### Added

- Optional `title`, `description` and `canonical` labels on workflow and node definitions, preserved through slot replacement and runtime child qualification. Canonicals are reusable labels; UUIDs and node keys retain their existing identity rules.
- Automatic constructor/handler signature descriptions and actual argument snapshots on step records, without changing existing step classes. Record resolved inputs and defaults, use descriptors for services/context, and mark values that cannot be encoded as unavailable.
- Fence argument writes by the current attempt and lease. Capturing a new action attempt replaces the snapshot; verification retries retain the completed action's arguments. Return payloads remain on individual steps; workflows expose no result payload.

### Upgrade

- Run ordinary `php artisan migrate` before starting updated workers. The additive `2026_11_14_193248_add_workflow_and_step_descriptions.php` migration adds nullable labels and step diagnostics; all ten previously published migrations remain unchanged.
- Existing definitions and step classes need no conversion. Existing rows retain null declaration snapshots. Argument snapshots contain business data and follow existing history retention. See [the recorded metadata contract](docs/guide.md#describe-workflows-and-inspect-step-invocations).

### Tests

- Add 19 regression cases covering labels and identity, reflection/defaults, context/services/variadics, resolved and mapped values, falsey results, retries, skipped actions, stale attempts, non-JSON defaults, and fresh/upgrade/repeat/rollback migration safety.

## 2.2.1 — 2026-11-14

### Fixed

- Recover lost deliveries and expired action/failure-handler leases under concurrent SQLite recovery commands. Bounded candidate batches close read cursors before acquiring write locks; eligibility and ownership are still rechecked under the workflow lock.

### Tests and maintenance

- Expand lifecycle, input/encoding, command/configuration and Step Dispatcher parity coverage, including mixed skipped/successful rescue chains and ancestor barriers.
- Exercise real Redis and database queue workers, duplicate delivery, recovery/cooldown collisions, worker loss and bounded scale scenarios. Provision Redis and its PHP extension in all six CI lanes.
- Make Redis quota-delay verification independent of worker startup speed with a shared test clock and exact before/due queue assertions. Name invalid UTF-8 datasets so test reporting and TIA metadata remain encodable.

### Upgrade

- No schema, dependency, configuration or public API change. Published migrations are unchanged. Reload long-running workers through the consumer’s normal deployment procedure to use the recovery fix.

## 2.2.0 — 2026-11-14

### Added

- Opt-in default database connection release before step actions, verification and terminal failure hooks, and when runners exit. Queries reconnect on demand; durable checkpoints and ownership guards remain unchanged.
- `Context::releaseDatabaseConnection(): bool` and `Execution\ConnectionRelease::release(): bool` for an explicit boundary after request preparation. Active transactions, persistent connections and in-memory SQLite are preserved.

### Upgrade

- No schema migration, dependency change or workflow/step signature change. Enable with `SCARLETT_RELEASE_DATABASE_CONNECTION=true` using the package configuration mapping; the default is disabled.
- Review session-local state and shared integrations before enabling. Queries inside an action can reconnect, and queue transport may reconnect after a runner exits. See [configuration, guarantees and limits](docs/database-connections.md).

## 2.1.0 — 2026-11-14

### Added

- Named, database-backed rolling-window rate limits with explicit sharing keys, atomic reservations and idempotent attempt receipts. Throttle waits remain separate from bounded action retries.
- Additive throttle schema and optional `scarlett:prune-throttles` housekeeping, with active-window rollback protection.

### Upgrade

- Run ordinary `php artisan migrate` before enabling `Scarlett\Policies\RateLimit`. Migration `2026_11_14_000009_add_throttle_reservations.php` adds shared quota storage and nullable attempt receipts; existing workflows and published migrations are preserved.
- Configure named `max_requests`/`per_seconds` quotas and return `RateLimit` from each applicable step's `throttle()` hook. Existing custom throttlers remain supported; an empty configuration adds no limits.
- Quotas permit rolling-window bursts, and failed calls retain their admission charge. API-error retries consume the existing failure budget and check quota again when due. See [configuration, limits and rollout](docs/throttling.md).

## 2.0.2 — 2026-11-14

### Documentation

- Explain responsibilities and invariants throughout the engine: admission lock order, attempt ownership, late inputs, action persistence, verification, cascading rescues, failure hooks and retention.
- Add a contributor architecture guide with execution and lock diagrams, the seven-table map, telemetry semantics and migration/test boundaries.
- Reconcile installation and lifecycle documentation. No runtime, public API, dependency or schema changes; all published migrations remain unchanged.

## 2.0.1 — 2026-11-14

### Performance

- Add eight query indexes for workflow creation/retention, parent and rescue relationships, step deliveries/history, workflow failure handlers and subject-slot lookup. No public API or stored record changes.
- Preserve unrestricted subject-slot text with database-specific indexes: bounded MySQL prefixes, PostgreSQL equality hashing and SQLite composite keys.

### Upgrade

- Run ordinary `php artisan migrate` after updating the package. The additive `2026_11_14_000008_add_query_indexes.php` migration preserves published migrations and existing data; rollback removes its indexes and preserves MySQL foreign-key support.
- Index creation uses ordinary database DDL and may block writes while building on large tables. Schedule it through the application’s maintenance process. This release does not claim a 10M-row benchmark or remove aggregate-query costs. See [index coverage and rollout](docs/database-indexes.md).

## 2.0.0 — 2026-11-14

### Features

- Atomic runtime child plans through `Decision::children()`, including staged fanout, nested backups, attempt fencing, retry/redelivery deduplication, inherited routing and normal cascading rescue behavior.
- Forward a selected child's durable result through the original parent identity; verify the parent after children finish without replaying its action. Missing selected results report `ChildResultMissing`.
- Additive runtime-child migration, discovered by ordinary Laravel migrations, with active-work rollback protection. Published migrations remain unchanged.

### Breaking changes and upgrade

- `Node::$priority` changes from `int = 0` to `?int = null` for runtime inheritance. Ordinary workflow admission still resolves omitted priority to zero; explicit zero overrides inherited priority. Code reading the definition property must handle null.
- `Engine` constructor dependencies changed. Resolve it through Laravel's container or the Scarlett facade instead of constructing it manually.
- `FailureCause::ChildResultMissing` is a new enum case. Update exhaustive failure-cause matches before enabling runtime plans.
- Run ordinary `php artisan migrate` before starting updated workers. See the [1.x upgrade checklist](docs/runtime-children.md#upgrade-from-scarlett-1x).

### API notes

- Runtime plans are closed: one per parent action, no mixing with predefined ordinary children, no external graph references or incremental appending. Existing predefined graphs retain their behavior.

## 1.0.0 — 2026-11-14

First public tagged release. The 0.1.x entries below record untagged development
milestones included in this release.

### Features

- Admission-first transactions for atomic consumer writes and follow-up workflows alongside subject deletion. Action and failure ownership checks now reject cancelled attempts inside older outer transaction snapshots.

- One-query current-workflow lookup for batches of subjects, preserving exact slot identities without consumer mirror columns.

- Package retention commands `scarlett:archive` and `scarlett:purge`, with explicit ages, bounded batches and locked live-work protection. Repeated archiving preserves the original archive timestamp.

- Atomic subject deletion across all workflow lanes and history, with pre-revocation vetoes and related-subject guards for consumer-owned checks.

- Atomic subject-slot replacement with rollback vetoes, current-workflow lookup and revocation of old execution ownership.
- Durable terminal failure hooks with typed causes, business-readable reasons, child-before-parent ordering, bounded recovery and persisted-input fallback.
- Static step execution defaults, overridden by explicit node options and snapshotted without early construction.

- Per-step execution settings persisted at admission, scheduled starts, workflow subjects and metadata, and execution timestamps.
- Execution context injection, guarded consumer writes, active-workflow cancellation and targeted history deletion.
- Configurable input-failure classification with bounded preparation retries and terminal invalid/deleted inputs.

### Fixes

- Recover unsent deliveries even when MySQL reports no changed values within the same timestamp; keep active publication leases and recent deliveries protected.
- Complete cascading recovery from the leaves upward, including nested and staged rescue branches, duplicate deliveries, expired leases and concurrently finishing siblings.
- Use current locked outcomes for progression, cancellation, terminal-hook ordering and retention inside older MySQL transaction snapshots.
- Prevent action replay after result persistence failure; verify the restored durable result consistently and recheck ownership after decoding.
- Validate completion transitions and attribute rejected completion guards to their owning step; preserve exact parent keys, including zero-valued parent/rescue result bindings.
- Keep publication faults recoverable, release early queue jobs until due, and fail unexpected transport errors/timeouts independently of cache counters.
- Apply a configurable positive wait to zero-second throttles without consuming failure retries.
- Preserve retention cutoff instants, named date timezones and meaningful scalar types; reject incompatible literal inputs before admission.
- Preserve exact MySQL step identities and schedules beyond 2038 through additive package migrations, with guarded narrowing rollbacks.

### Maintenance

- Run the complete Pest suite on SQLite, MySQL and PostgreSQL, including package migrations, real queue workers and database-specific contention checks; retain all three databases in the PHP 8.4/8.5 CI matrix.
- [IMPROVED] Simplify the README around runnable Laravel examples and preserve the full reference in a linked guide.
- Add Scarlett branding, visual workflow diagrams and a Laravel-focused README with a runnable quick start.
- Disclose AI-assisted development with a README badge and transparency note.
- Reduce repeated graph hydration and publication lookups; cache validated state configuration and narrow recovery candidates.
- Expand engine, consumer, migration, concurrent-ownership and real-worker regression coverage.
- Document progressive consumer migration, package upgrades, failure semantics and consumer acceptance gates.

## 0.1.1 — 2026-11-13

### Fixes

- [BUG FIX] Recheck attempt ownership between throttle hooks and preconditions so obsolete work cannot enter later hooks.

## 0.1.0 — 2026-11-13

### Features

- [NEW FEATURE] Outcome-driven Laravel workflows with sequential/parallel stages, predefined nesting and exception recovery.
- [NEW FEATURE] Late typed step construction, versioned payload mappers, explicit service/model bindings and configurable Spatie states.
- [NEW FEATURE] Retry, throttle, confirmation, cooldown, cancellation, recovery, diagnostics and protected history retention.
- [NEW FEATURE] Durable delivery intents, attempt fencing and real-worker collision/transport tests.

### Dependencies

- [DEPENDENCIES] Laravel 13, PHP 8.4+, Spatie model-states 2, Pest 5 and Testbench 11.
