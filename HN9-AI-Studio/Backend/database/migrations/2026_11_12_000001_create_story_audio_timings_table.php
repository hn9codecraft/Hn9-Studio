<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('story_audio_timings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('story_scene_audio_id')->unique()->constrained('story_scene_audios')->cascadeOnDelete();
            $table->string('checksum')->nullable();
            $table->unsignedInteger('duration_ms');
            $table->string('alignment_source', 16);
            $table->string('alignment_disk')->nullable();
            $table->string('alignment_path')->nullable();
            $table->timestamp('measured_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('story_audio_timings');
    }
};
