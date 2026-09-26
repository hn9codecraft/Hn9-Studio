<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Runtime Project Story reels. Ownership via Story Workspace → Project.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('story_reels', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('story_workspace_id')
                ->constrained('story_workspaces')
                ->cascadeOnDelete();

            $table->string('title');
            $table->text('description')->nullable();
            $table->unsignedInteger('sequence')->default(1);
            $table->string('status')->default('draft')->index();
            $table->unsignedInteger('total_duration_seconds')->default(0);

            $table->foreignId('source_plan_id')
                ->nullable()
                ->constrained('story_plans')
                ->nullOnDelete();

            $table->foreignId('source_plan_version_id')
                ->nullable()
                ->constrained('story_plan_versions')
                ->nullOnDelete();

            $table->timestamps();

            $table->index(['story_workspace_id', 'status']);
            $table->index(['story_workspace_id', 'sequence']);
            $table->unique(['story_workspace_id', 'source_plan_version_id'], 'story_reels_workspace_plan_version_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('story_reels');
    }
};
