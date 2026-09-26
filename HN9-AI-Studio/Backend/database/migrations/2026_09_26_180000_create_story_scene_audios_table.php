<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Generic scene audio records. One table for all roles — not one table per vendor.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('story_scene_audios', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('story_workspace_id')
                ->constrained('story_workspaces')
                ->cascadeOnDelete();

            $table->foreignId('story_scene_id')
                ->constrained('story_scenes')
                ->cascadeOnDelete();

            $table->foreignId('story_scene_version_id')
                ->nullable()
                ->constrained('story_scene_versions')
                ->nullOnDelete();

            $table->foreignId('story_video_generation_job_id')
                ->nullable()
                ->constrained('story_video_generation_jobs')
                ->nullOnDelete();

            $table->string('role');
            $table->string('status')->default('queued')->index();
            $table->string('prompt')->nullable();
            $table->string('idempotency_key')->nullable();

            $table->string('disk')->nullable();
            $table->string('path')->nullable();
            $table->string('mime')->nullable();
            $table->unsignedBigInteger('size')->nullable();
            $table->string('checksum')->nullable();

            $table->string('error_code')->nullable();
            $table->text('error_message')->nullable();

            $table->timestamps();

            $table->unique(['story_workspace_id', 'idempotency_key'], 'story_scene_audios_workspace_idempotency_unique');
            $table->index(['story_scene_id', 'role']);
            $table->index(['story_scene_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('story_scene_audios');
    }
};
