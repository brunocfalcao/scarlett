<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('scarlett_deliveries', function (Blueprint $table): void {
            $table->timestamp('publishing_until')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('scarlett_deliveries', fn (Blueprint $table) => $table->dropColumn('publishing_until'));
    }
};
