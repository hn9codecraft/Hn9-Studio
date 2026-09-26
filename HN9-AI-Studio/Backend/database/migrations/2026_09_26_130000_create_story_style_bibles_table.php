<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Project Story Style Bible. One per Story Workspace.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('story_style_bibles', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('story_workspace_id')
                ->unique()
                ->constrained('story_workspaces')
                ->cascadeOnDelete();

            $table->string('visual_style')->nullable();
            $table->string('animation_style')->nullable();
            $table->string('lighting')->nullable();
            $table->string('camera_style')->nullable();
            $table->string('color_direction')->nullable();
            $table->string('environment_style')->nullable();
            $table->string('mood')->nullable();
            $table->string('rendering_style')->nullable();
            $table->string('visual_quality')->nullable();
            $table->text('art_direction_notes')->nullable();
            $table->string('aspect_ratio')->nullable();

            $table->unsignedBigInteger('approved_reference_id')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('story_style_bibles');
    }
};
