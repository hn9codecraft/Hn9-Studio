<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Timeline clips reference stored scene video and audio. They do not copy media.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('story_timelines', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('story_reel_id')->unique()->constrained('story_reels')->cascadeOnDelete();
            $table->timestamps();
        });

        Schema::create('story_timeline_clips', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('story_timeline_id')->constrained('story_timelines')->cascadeOnDelete();
            $table->unsignedInteger('position');
            $table->string('media_kind');
            $table->foreignId('story_scene_version_id')->nullable()->constrained('story_scene_versions')->nullOnDelete();
            $table->foreignId('story_scene_audio_id')->nullable()->constrained('story_scene_audios')->nullOnDelete();
            $table->string('disk');
            $table->string('path');
            $table->unsignedInteger('in_ms')->default(0);
            $table->unsignedInteger('out_ms');
            $table->timestamps();
            $table->index(['story_timeline_id', 'position']);
        });

        Schema::create('story_timeline_transitions', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('story_timeline_id')->constrained('story_timelines')->cascadeOnDelete();
            $table->foreignId('from_clip_id')->constrained('story_timeline_clips')->cascadeOnDelete();
            $table->foreignId('to_clip_id')->constrained('story_timeline_clips')->cascadeOnDelete();
            $table->string('type');
            $table->unsignedInteger('duration_ms')->default(0);
            $table->timestamps();
            $table->unique('from_clip_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('story_timeline_transitions');
        Schema::dropIfExists('story_timeline_clips');
        Schema::dropIfExists('story_timelines');
    }
};
