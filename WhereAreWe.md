# Current state

Reviewed 2026-11-14 for the first public release, **v1.0.0**. Scope: the
complete package snapshot, including cascading recovery, current transaction
reads, database-matrix tests and consumer documentation. The 0.1.x changelog
entries are untagged development milestones.

Implemented: outcome-driven progression, late typed inputs/results, staged
parent/child and rescue graphs, bounded retries and throttling, durable failure
handlers, fenced consumer writes, subject slots/deletion, recovery and retention.
Cascading recovery settles descendant branches before failing their parents.
Engine mutations use current locked outcomes inside enclosing transactions.
Unsent delivery recovery uses locked eligibility rather than affected-row counts.

Validation targets: the complete Pest suite on SQLite, MySQL 9.7 and PostgreSQL
17, package migration fresh/upgrade/repeat/rollback scenarios, independent queue
workers and contention, plus the Laravel reference host. CI runs the complete
suite on all three databases for PHP 8.4 and 8.5. Local full runs rebuild Pest's
TIA graph; the unversioned host uses its documented exhaustive bootstrap path.

Local verification passed on 2026-11-14: MySQL's final full run executed 330 tests
(3,539 assertions); SQLite and PostgreSQL full runs executed 327 tests before the
last recovery fix, followed by all 25 affected recovery/ownership/queue cases on
each database, including its three new negative cases. The final Laravel host
run passed 14 tests (56 assertions). Pint, Composer validation and local doc links
passed. CI is configured to execute all 330 tests in each of its six lanes.

Publication and exact-commit CI status are recorded by the GitHub tag, Release and
Actions run. No consumer deployment or production acceptance is claimed.

SQLite concurrency requires the documented WAL/immediate-transaction setup and
serializes writers. External effects require idempotency. Dynamic graph extension
and table-prefix execution scopes remain deferred. No package migrations were
rewritten. Consumer code and databases are outside this release's edit scope.
