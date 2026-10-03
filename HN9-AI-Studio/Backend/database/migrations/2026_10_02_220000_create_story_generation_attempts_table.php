<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Story generation attempts that failed before a generation job existed: no provider
 * connected, or a planner/reference request rejected by the provider. Successful work
 * stays on its own records (jobs, plan versions, references).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('story_generation_attempts', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('story_workspace_id')->constrained('story_workspaces')->cascadeOnDelete();
            $table->foreignId('story_reel_id')->nullable()->constrained('story_reels')->nullOnDelete();
            $table->foreignId('story_scene_id')->nullable()->constrained('story_scenes')->nullOnDelete();
            $table->string('kind', 40);
            $table->string('capability', 40)->nullable();
            $table->string('status', 32);
            $table->string('error_code', 80)->nullable();
            $table->string('error_message', 300)->nullable();
            $table->timestamps();

            $table->index(['story_workspace_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('story_generation_attempts');
    }
};
