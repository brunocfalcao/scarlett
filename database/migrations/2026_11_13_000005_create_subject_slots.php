<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scarlett_subject_slots', function (Blueprint $table): void {
            $table->string('id', 64)->primary();
            $table->text('subject_type');
            $table->text('subject_id');
            $table->text('name');
            // No FK: history deletion must not acquire a slot lock after a workflow lock.
            $table->uuid('workflow_id')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scarlett_subject_slots');
    }
};
