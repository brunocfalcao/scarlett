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
        Schema::create('scarlett_control', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->boolean('cooldown')->default(false);
        });
        DB::table('scarlett_control')->insert(['id' => 'admission', 'cooldown' => false]);
        Schema::create('scarlett_workflows', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('status')->index();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamp('archived_at')->nullable()->index();
            $table->timestamps();
        });
        Schema::create('scarlett_steps', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('workflow_id')->constrained('scarlett_workflows')->restrictOnDelete();
            $table->string('key');
            $table->string('class');
            $table->string('state')->index();
            $table->string('parent_key')->nullable();
            $table->string('rescues_key')->nullable();
            $table->json('dependencies');
            $table->json('inputs');
            $table->json('result')->nullable();
            $table->boolean('has_result')->default(false);
            $table->string('completion_state')->nullable();
            $table->integer('priority')->default(0);
            $table->string('queue');
            $table->string('connection')->nullable();
            $table->uuid('attempt')->nullable();
            $table->unsignedInteger('failures')->default(0);
            $table->unsignedInteger('verifications')->default(0);
            $table->boolean('action_completed')->default(false);
            $table->timestamp('available_at')->nullable();
            $table->timestamp('lease_until')->nullable()->index();
            $table->text('error')->nullable();
            $table->timestamps();
            $table->unique(['workflow_id', 'key']);
        });
        Schema::create('scarlett_deliveries', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('step_id')->constrained('scarlett_steps')->restrictOnDelete();
            $table->uuid('attempt')->unique();
            $table->timestamp('available_at');
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();
        });
        Schema::create('scarlett_history', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->foreignUuid('step_id')->constrained('scarlett_steps')->restrictOnDelete();
            $table->uuid('attempt')->nullable();
            $table->string('from_state');
            $table->string('to_state');
            $table->text('reason')->nullable();
            $table->timestamp('created_at');
        });
    }

    public function down(): void
    {
        foreach (['scarlett_history', 'scarlett_deliveries', 'scarlett_steps', 'scarlett_workflows', 'scarlett_control'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
