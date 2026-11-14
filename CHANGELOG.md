# Changelog

## Unreleased

### Features

- Atomic runtime child plans through `Decision::children()`, including staged fanout, nested backups, attempt fencing, retry/redelivery deduplication, inherited routing and normal cascading rescue behavior.
- Forward a selected child's durable result through the original parent identity; verify the parent after children finish without replaying its action. Missing selected results report `ChildResultMissing`.
- Additive runtime-child migration, discovered by ordinary Laravel migrations, with active-work rollback protection. Published migrations remain unchanged.

### API notes

- `Node` priority accepts `null` for runtime inheritance; ordinary workflow admission still defaults to zero. Explicit zero overrides inherited priority.
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
