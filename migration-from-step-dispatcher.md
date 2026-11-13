# Migrating from Step Dispatcher

Scarlett is a fresh integration. No old classes, hooks, tables, state aliases or
serialized jobs are compatible. Do not point Scarlett at existing dispatcher tables.

1. Inventory each consumer's step classes, stages, parent/child graph, recovery
   paths, retry/verification rules, routing and result dependencies.
2. Rewrite jobs as `Scarlett\Step` classes with explicit typed constructors and
   `handle` methods. Bind required input names in `Definition\Node`.
3. Replace indexed dispatch calls with a complete `Definition\Workflow` and
   `Engine::start` / the Scarlett facade. Same-stage siblings remain parallel.
4. Replace eager construction with class references and `Inputs\FromResult`.
   Use `Inputs\Service` for services and register DTO/enum/model aliases.
5. Translate prechecks to `before`, confirmation to `verify`, error classification
   to `onException`, and rate limits to `Throttle` / `Throttled`.
6. Predefine parent children and `rescues` branches. Dynamic child creation and
   general delayed scheduling have no v1 equivalent. Redesign those consumers
   before migrating them. Prefix-scoped tables are also deferred.
7. Rebuild status queries and dashboards around workflow records, named Spatie
   states and transition history. A successful recovery does not erase its failure.
8. Configure queue routing, lease/timeout/backoff and the recovery command. Remove
   normal dispatcher polling only after the migrated consumer passes its tests.
9. Add business idempotency keys for external effects. Test process crashes,
   obsolete workers, real queue transport and concurrent completions.
10. Drain old workflows before cutover. Keep old history independently readable
    until its retention policy permits removal. Do not convert running old jobs.

The Laravel test host is a reference consumer. Its business tests and worker E2E
are separate from the reusable package tests. Existing consumer projects have not
been migrated by creating Scarlett.
