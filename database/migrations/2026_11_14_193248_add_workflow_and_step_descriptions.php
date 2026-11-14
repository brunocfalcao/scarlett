<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['scarlett_workflows', 'scarlett_steps'] as $name) {
            foreach (['title', 'description', 'canonical'] as $column) {
                if (! Schema::hasColumn($name, $column)) {
                    Schema::table($name, fn (Blueprint $table) => $table->text($column)->nullable());
                }
            }
        }
        foreach (['signature', 'arguments'] as $column) {
            if (! Schema::hasColumn('scarlett_steps', $column)) {
                Schema::table('scarlett_steps', fn (Blueprint $table) => $table->json($column)->nullable());
            }
        }
    }

    public function down(): void
    {
        foreach (['scarlett_steps' => ['signature', 'arguments', 'title', 'description', 'canonical'], 'scarlett_workflows' => ['title', 'description', 'canonical']] as $name => $columns) {
            foreach ($columns as $column) {
                if (Schema::hasColumn($name, $column)) {
                    Schema::table($name, fn (Blueprint $table) => $table->dropColumn($column));
                }
            }
        }
    }
};
