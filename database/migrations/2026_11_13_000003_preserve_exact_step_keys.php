<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! in_array(Schema::getConnection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            return;
        }
        Schema::table('scarlett_steps', function (Blueprint $table): void {
            // 255 UTF-8 characters occupy at most 1020 bytes; binary comparison also preserves trailing spaces.
            $table->binary('key', length: 1020)->change();
            $table->binary('parent_key', length: 1020)->nullable()->change();
            $table->binary('rescues_key', length: 1020)->nullable()->change();
        });
    }

    public function down(): void
    {
        if (! in_array(Schema::getConnection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            return;
        }
        Schema::table('scarlett_steps', function (Blueprint $table): void {
            // The unique index refuses rollback when distinct keys would collapse under the old collation.
            $table->string('key')->change();
            $table->string('parent_key')->nullable()->change();
            $table->string('rescues_key')->nullable()->change();
        });
    }
};
