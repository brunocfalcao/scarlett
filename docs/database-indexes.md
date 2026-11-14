# Query indexes and large-table upgrades

Available in **v2.0.1**. The package provider discovers
`2026_11_14_000008_add_query_indexes.php`; consuming Laravel projects run their
normal `php artisan migrate` after updating Scarlett. Do not publish/copy package
migrations or edit files already recorded in the migration ledger.

## Added coverage

| Table | Index columns | Existing query served |
| --- | --- | --- |
| `scarlett_workflows` | `created_at, id` | Creation windows and newest-first workflow lists |
| `scarlett_workflows` | `archived_at, finished_at, id` | Unarchived workflows finished before a retention cutoff |
| `scarlett_steps` | `workflow_id, parent_key` | Child checks and parent counts |
| `scarlett_steps` | `workflow_id, rescues_key` | Recovery relationship lookup |
| `scarlett_deliveries` | `step_id, sent_at` | Per-step deliveries, unsent delivery joins and cleanup |
| `scarlett_history` | `step_id, created_at, id` | Ordered step transitions and cleanup |
| `scarlett_failures` | `workflow_id, status` | Workflow handlers, unfinished checks and cleanup |
| `scarlett_subject_slots` | Database-specific subject lookup | All slots for a subject during deletion |

Existing primary, unique, state, lease, scheduling, archive and workflow-subject
indexes remain in place. The control table already uses its primary admission ID.
These indexes serve current queries; they do not change step eligibility, lock
order, stored values, uniqueness, routing or failure/recovery semantics.

Subject-slot identity columns are unrestricted text. SQLite uses
`subject_id, subject_type`. MySQL uses the first 191 characters of both columns,
keeping the index bounded for UTF-8 while retaining the complete stored strings.
PostgreSQL uses a single-column hash index on `subject_id`, avoiding the B-tree
entry-size limit for long identities. Full subject predicates and the engine's
exact identity checks still apply. A shared prefix/hash is not a unique identity;
these lookup indexes are non-unique. The existing hashed slot primary key still
serves individual and batched current-workflow reads.

## Upgrade and rollback

The migration only adds indexes, and can resume after partially completed MySQL
DDL without recreating its already named indexes. Repeated Laravel migrations are
a no-op. Rollback removes only these named indexes. InnoDB can discard an implicit
foreign-key index after a covering composite is added, so rollback restores any
missing original FK-support indexes before dropping the composite replacements.
It never removes a foreign key, record or column.

Use the application's normal maintenance window and migration procedure. This
migration uses ordinary DDL, not concurrent/online index-building guarantees.
On large tables, building indexes takes disk space and may block reads or writes,
depending on the database and locks. Test the upgrade on a representative copy,
plan capacity and downtime, then deploy through the consumer's approved workflow.
No consumer database is migrated by the package release process.

## Evidence and limits

`tests/Feature/PackageMigrationsTest.php` verifies provider-discovered fresh
installation, retained populated data through upgrade/rollback, repeat execution,
index column order and non-uniqueness, and long subject IDs with shared prefixes.
The same suite runs on SQLite, MySQL and PostgreSQL.

This is index coverage, not a 10M-row performance certification. Broad dashboard
counts/grouping still process matching rows; substring class searches and large
individual workflow graphs need workload-specific evaluation. Archive marks rows
in place; purge actually removes eligible history. Retention ages remain the
consumer's decision. Review query plans and measured latency on representative
volumes before setting a capacity claim.
