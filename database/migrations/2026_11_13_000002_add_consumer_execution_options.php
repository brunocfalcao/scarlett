<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('scarlett_workflows', function (Blueprint $table): void {
            $table->string('subject_type')->nullable();
            $table->string('subject_id')->nullable();
            $table->json('metadata')->nullable();
            $table->index(['subject_type', 'subject_id']);
        });
        Schema::table('scarlett_steps', function (Blueprint $table): void {
            $table->json('execution')->nullable();
            $table->timestamp('not_before')->nullable()->index();
            $table->timestamp('started_at')->nullable()->index();
            $table->timestamp('finished_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('scarlett_steps', function (Blueprint $table): void {
            $table->dropIndex(['not_before']);
            $table->dropIndex(['started_at']);
            $table->dropColumn(['execution', 'not_before', 'started_at', 'finished_at']);
        });
        Schema::table('scarlett_workflows', function (Blueprint $table): void {
            $table->dropIndex(['subject_type', 'subject_id']);
            $table->dropColumn(['subject_type', 'subject_id', 'metadata']);
        });
    }
};
