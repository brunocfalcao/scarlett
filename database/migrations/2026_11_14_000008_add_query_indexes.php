<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Text prefixes bound MySQL index size without restricting stored identities. */
    private const MYSQL_SUBJECT_PREFIX_LENGTH = 191;

    private const INDEXES = [
        'scarlett_workflows' => [
            'scarlett_workflows_created_lookup' => ['created_at', 'id'],
            'scarlett_workflows_retention_lookup' => ['archived_at', 'finished_at', 'id'],
        ],
        'scarlett_steps' => [
            'scarlett_steps_parent_lookup' => ['workflow_id', 'parent_key'],
            'scarlett_steps_rescue_lookup' => ['workflow_id', 'rescues_key'],
        ],
        'scarlett_deliveries' => [
            'scarlett_deliveries_step_sent_lookup' => ['step_id', 'sent_at'],
        ],
        'scarlett_history' => [
            'scarlett_history_step_created_lookup' => ['step_id', 'created_at', 'id'],
        ],
        'scarlett_failures' => [
            'scarlett_failures_workflow_status_lookup' => ['workflow_id', 'status'],
        ],
    ];

    public function up(): void
    {
        foreach (self::INDEXES as $table => $indexes) {
            foreach ($indexes as $name => $columns) {
                // MySQL DDL is not transactional: a retry can encounter earlier completed indexes.
                if (! Schema::hasIndex($table, $name)) {
                    Schema::table($table, fn (Blueprint $blueprint) => $blueprint->index($columns, $name));
                }
            }
        }

        if (! Schema::hasIndex('scarlett_subject_slots', 'scarlett_slots_subject_lookup')) {
            $driver = Schema::getConnection()->getDriverName();
            Schema::table('scarlett_subject_slots', function (Blueprint $table) use ($driver): void {
                if (in_array($driver, ['mysql', 'mariadb'], true)) {
                    $length = self::MYSQL_SUBJECT_PREFIX_LENGTH;
                    $table->rawIndex("`subject_id`({$length}), `subject_type`({$length})", 'scarlett_slots_subject_lookup');
                } elseif ($driver === 'pgsql') {
                    // Hash equality indexes accept arbitrary text sizes; full predicates still check both identities.
                    $table->index('subject_id', 'scarlett_slots_subject_lookup', 'hash');
                } else {
                    $table->index(['subject_id', 'subject_type'], 'scarlett_slots_subject_lookup');
                }
            });
        }
    }

    public function down(): void
    {
        if (in_array(Schema::getConnection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            // InnoDB may discard its implicit FK index when a new composite covers it.
            // Restore the original supporting index before removing our replacement.
            foreach (['scarlett_deliveries' => 'step_id', 'scarlett_history' => 'step_id', 'scarlett_failures' => 'workflow_id'] as $table => $column) {
                $name = $table.'_'.$column.'_foreign';
                if (! Schema::hasIndex($table, $name)) {
                    Schema::table($table, fn (Blueprint $blueprint) => $blueprint->index($column, $name));
                }
            }
        }

        foreach (self::INDEXES + ['scarlett_subject_slots' => ['scarlett_slots_subject_lookup' => []]] as $table => $indexes) {
            foreach (array_keys($indexes) as $name) {
                if (Schema::hasIndex($table, $name)) {
                    Schema::table($table, fn (Blueprint $blueprint) => $blueprint->dropIndex($name));
                }
            }
        }
    }
};
