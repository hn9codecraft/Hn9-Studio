<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One ledger row per Story generation job that reached a terminal state.
 * cost stays null unless the provider reported it; cost_source records why a cost exists.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('story_usage_ledger_entries', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('story_video_generation_job_id')
                ->unique()
                ->constrained('story_video_generation_jobs')
                ->cascadeOnDelete();
            $table->foreignId('story_workspace_id')->constrained('story_workspaces')->cascadeOnDelete();
            $table->string('capability');
            $table->string('provider_key')->nullable();
            $table->string('model_key')->nullable();
            $table->string('operation_id')->nullable();
            $table->string('status', 32);
            $table->decimal('cost', 14, 6)->nullable();
            $table->string('currency', 8)->nullable();
            $table->string('cost_source')->nullable();
            $table->timestamp('recorded_at');
            $table->timestamps();

            $table->index(['story_workspace_id', 'recorded_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('story_usage_ledger_entries');
    }
};
