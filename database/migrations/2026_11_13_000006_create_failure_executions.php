<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scarlett_failures', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('workflow_id')->constrained('scarlett_workflows')->restrictOnDelete();
            $table->foreignUuid('step_id')->unique()->constrained('scarlett_steps')->restrictOnDelete();
            $table->string('cause');
            $table->text('reason')->nullable();
            $table->json('snapshot');
            $table->string('status')->index();
            $table->uuid('attempt')->nullable()->unique();
            $table->unsignedInteger('failures')->default(0);
            $table->dateTime('available_at')->nullable();
            $table->dateTime('lease_until')->nullable();
            $table->dateTime('sent_at')->nullable();
            $table->dateTime('publishing_until')->nullable();
            $table->dateTime('finished_at')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scarlett_failures');
    }
};
