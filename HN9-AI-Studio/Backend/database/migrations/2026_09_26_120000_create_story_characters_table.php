<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Project Story characters. Ownership is Story Workspace → Project.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('story_characters', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('story_workspace_id')
                ->constrained('story_workspaces')
                ->cascadeOnDelete();

            $table->string('name');
            $table->text('short_description')->nullable();
            $table->string('age')->nullable();
            $table->string('gender_presentation')->nullable();
            $table->text('appearance')->nullable();
            $table->text('face_description')->nullable();
            $table->string('hair')->nullable();
            $table->text('clothing')->nullable();
            $table->text('personality')->nullable();
            $table->text('voice_description')->nullable();
            $table->text('special_details')->nullable();
            $table->string('status')->default('draft')->index();
            $table->unsignedInteger('sort_order')->default(0);
            $table->unsignedBigInteger('approved_reference_id')->nullable();
            $table->timestamps();

            $table->index(['story_workspace_id', 'status']);
            $table->index(['story_workspace_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('story_characters');
    }
};
