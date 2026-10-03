<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One FFmpeg build of a production scene from its selected unit versions.
 * A later selection creates another row. The scene version used for story
 * review stays a different record.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('story_scene_assemblies', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('story_workspace_id')
                ->constrained('story_workspaces')
                ->cascadeOnDelete();
            $table->foreignId('story_production_plan_scene_id')
                ->constrained('story_production_plan_scenes')
                ->cascadeOnDelete();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();

            $table->unsignedInteger('version_number')->nullable();
            $table->string('idempotency_key');
            $table->string('status', 32)->default('queued')->index();
            $table->json('snapshot');
            $table->unsignedInteger('expected_duration_seconds');

            $table->string('disk')->nullable();
            $table->string('path')->nullable();
            $table->string('mime')->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->decimal('duration_seconds', 8, 2)->nullable();

            $table->string('error_code')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamps();

            $table->unique(['story_workspace_id', 'idempotency_key'], 'story_scene_assemblies_intent_unique');
            $table->unique(['story_production_plan_scene_id', 'version_number'], 'story_scene_assemblies_version_unique');
            $table->index(['story_production_plan_scene_id', 'status'], 'story_scene_assemblies_scene_status_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('story_scene_assemblies');
    }
};
