<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('story_subtitle_tracks', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('story_workspace_id')->constrained('story_workspaces')->cascadeOnDelete();
            $table->foreignId('story_reel_id')->constrained('story_reels')->cascadeOnDelete();
            $table->foreignId('source_render_id')->constrained('story_final_renders')->cascadeOnDelete();
            $table->foreignId('burned_render_id')->nullable()->constrained('story_final_renders')->nullOnDelete();
            $table->string('language', 10);
            $table->string('timing_mode', 16);
            $table->unsignedInteger('cue_count');
            $table->string('cues_hash', 64);
            $table->json('cues');
            $table->string('disk');
            $table->string('srt_path');
            $table->string('vtt_path');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['source_render_id', 'cues_hash']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('story_subtitle_tracks');
    }
};
