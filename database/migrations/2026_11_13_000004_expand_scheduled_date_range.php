<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const COLUMNS = [
        'scarlett_steps' => ['not_before' => true, 'available_at' => true],
        'scarlett_deliveries' => ['available_at' => false],
    ];

    public function up(): void
    {
        foreach (self::COLUMNS as $name => $columns) {
            Schema::table($name, function (Blueprint $table) use ($columns): void {
                foreach ($columns as $column => $nullable) {
                    $table->dateTime($column)->nullable($nullable)->change();
                }
            });
        }
    }

    public function down(): void
    {
        if (in_array(Schema::getConnection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            // Refuse narrowing before any DDL: these are MySQL TIMESTAMP's storage bounds.
            foreach (self::COLUMNS as $name => $columns) {
                foreach ($columns as $column => $nullable) {
                    if (DB::table($name)->whereNotBetween($column, ['1970-01-01 00:00:01', '2038-01-19 03:14:07'])->exists()) {
                        throw new RuntimeException('Cannot restore TIMESTAMP while scheduled dates exceed its storage range.');
                    }
                }
            }
        }
        foreach (self::COLUMNS as $name => $columns) {
            Schema::table($name, function (Blueprint $table) use ($columns): void {
                foreach ($columns as $column => $nullable) {
                    $table->timestamp($column)->nullable($nullable)->change();
                }
            });
        }
    }
};
