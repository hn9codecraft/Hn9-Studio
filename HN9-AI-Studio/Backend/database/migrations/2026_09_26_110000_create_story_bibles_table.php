<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One Story Bible per Story Workspace. Ownership follows workspace → project.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('story_bibles', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('story_workspace_id')
                ->constrained('story_workspaces')
                ->cascadeOnDelete();

            $table->text('concept')->nullable();
            $table->string('genre')->nullable();
            $table->string('audience')->nullable();
            $table->string('language', 10)->nullable();
            $table->string('tone')->nullable();
            $table->text('world')->nullable();
            $table->string('location')->nullable();
            $table->string('time_period')->nullable();
            $table->string('narrative_style')->nullable();
            $table->string('video_style')->nullable();
            $table->string('aspect_ratio', 16)->nullable();
            $table->unsignedInteger('default_duration')->nullable();
            $table->json('audio_defaults')->nullable();
            $table->timestamps();

            $table->unique('story_workspace_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('story_bibles');
    }
};
