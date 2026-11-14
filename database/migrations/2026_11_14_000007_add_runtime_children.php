<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('scarlett_steps', function (Blueprint $table): void {
            $table->string('child_phase')->nullable();
            $table->string('child_result_key')->nullable();
        });
    }

    public function down(): void
    {
        $active = DB::table('scarlett_steps')->whereNotNull('child_phase')
            ->whereIn('workflow_id', DB::table('scarlett_workflows')->select('id')->whereNull('finished_at'))->exists();
        if ($active) {
            throw new RuntimeException('Drain runtime child workflows before rolling back their schema.');
        }
        Schema::table('scarlett_steps', fn (Blueprint $table) => $table->dropColumn(['child_phase', 'child_result_key']));
    }
};
