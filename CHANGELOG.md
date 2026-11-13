# Changelog

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
