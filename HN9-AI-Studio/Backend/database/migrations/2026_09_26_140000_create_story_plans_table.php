<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Project Story plans. Ownership inherited via Story Workspace → Project.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('story_plans', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('story_workspace_id')
                ->constrained('story_workspaces')
                ->cascadeOnDelete();

            $table->string('title')->nullable();
            $table->text('idea');
            $table->unsignedInteger('requested_duration_seconds');
            $table->string('duration_unit')->default('seconds');
            $table->string('status')->default('draft')->index();
            $table->unsignedBigInteger('current_version_id')->nullable();
            $table->timestamps();

            $table->index(['story_workspace_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('story_plans');
    }
};
