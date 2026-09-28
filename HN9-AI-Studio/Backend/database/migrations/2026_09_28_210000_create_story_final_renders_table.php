<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('story_final_renders', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('story_timeline_id')->constrained('story_timelines')->cascadeOnDelete();
            $table->foreignId('story_reel_id')->constrained('story_reels')->cascadeOnDelete();
            $table->string('status', 32);
            $table->string('timeline_version', 64);
            $table->json('timeline_snapshot');
            $table->string('disk')->nullable();
            $table->string('path')->nullable();
            $table->string('mime')->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->string('checksum', 64)->nullable();
            $table->string('error_code')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->index('story_reel_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('story_final_renders');
    }
};
