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
        // Both guards allow retry after partially applied, non-transactional MySQL DDL.
        if (! Schema::hasTable('scarlett_throttles')) {
            Schema::create('scarlett_throttles', function (Blueprint $table): void {
                $table->string('id', 64)->primary();
                $table->json('grants');
                $table->unsignedBigInteger('max_requests');
                $table->unsignedBigInteger('per_seconds');
                // UTC epoch milliseconds preserve subsecond boundaries on all supported drivers.
                $table->unsignedBigInteger('expires_at')->index();
            });
        }
        if (! Schema::hasColumn('scarlett_steps', 'throttle_reservations')) {
            Schema::table('scarlett_steps', fn (Blueprint $table) => $table->json('throttle_reservations')->nullable());
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('scarlett_throttles') && DB::table('scarlett_throttles')->where('expires_at', '>', now()->getTimestampMs())->exists()) {
            throw new RuntimeException('Wait for active throttle windows to expire before rolling back their schema.');
        }
        if (Schema::hasColumn('scarlett_steps', 'throttle_reservations')) {
            Schema::table('scarlett_steps', fn (Blueprint $table) => $table->dropColumn('throttle_reservations'));
        }
        Schema::dropIfExists('scarlett_throttles');
    }
};
