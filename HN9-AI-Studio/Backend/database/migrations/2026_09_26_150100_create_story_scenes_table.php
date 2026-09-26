<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Runtime Project Story scenes. No video binaries in M11.5.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('story_scenes', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('story_reel_id')
                ->constrained('story_reels')
                ->cascadeOnDelete();

            $table->unsignedInteger('sequence')->default(1);
            $table->string('title')->nullable();
            $table->unsignedInteger('duration_seconds')->default(30);
            $table->unsignedInteger('start_second')->default(0);
            $table->unsignedInteger('end_second')->default(30);
            $table->string('status')->default('draft')->index();

            $table->text('story')->nullable();
            $table->json('characters')->nullable();
            $table->string('location')->nullable();
            $table->json('dialogue')->nullable();
            $table->text('narration')->nullable();
            $table->text('visual_prompt')->nullable();
            $table->text('motion_prompt')->nullable();
            $table->text('audio_direction')->nullable();
            $table->json('continuity')->nullable();

            $table->foreignId('source_plan_version_id')
                ->nullable()
                ->constrained('story_plan_versions')
                ->nullOnDelete();

            $table->timestamps();

            $table->index(['story_reel_id', 'status']);
            $table->index(['story_reel_id', 'sequence']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('story_scenes');
    }
};
