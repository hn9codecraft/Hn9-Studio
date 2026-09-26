<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Generic Story video generation jobs. No scene media / final assets in M11.6.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('story_video_generation_jobs', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('story_workspace_id')
                ->constrained('story_workspaces')
                ->cascadeOnDelete();

            $table->foreignId('story_reel_id')
                ->nullable()
                ->constrained('story_reels')
                ->nullOnDelete();

            $table->foreignId('story_scene_id')
                ->nullable()
                ->constrained('story_scenes')
                ->nullOnDelete();

            $table->string('capability');
            $table->string('provider_key')->nullable();
            $table->string('model_key')->nullable();
            $table->string('operation_id')->nullable();
            $table->string('status')->default('queued')->index();
            $table->string('async_mode')->nullable();
            $table->string('idempotency_key')->nullable();

            $table->json('request_payload')->nullable();
            $table->json('routing')->nullable();
            $table->json('provider_metadata')->nullable();

            $table->string('error_code')->nullable();
            $table->text('error_message')->nullable();
            $table->unsignedInteger('retry_count')->default(0);
            $table->boolean('timed_out')->default(false);

            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamps();

            $table->unique(['story_workspace_id', 'idempotency_key'], 'story_video_jobs_workspace_idempotency_unique');
            $table->index(['story_workspace_id', 'status']);
            $table->index(['provider_key', 'operation_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('story_video_generation_jobs');
    }
};
