<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * History of version restores. Restoring never deletes the version it replaces.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('story_version_restores', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('story_workspace_id')
                ->constrained('story_workspaces')
                ->cascadeOnDelete();
            $table->string('subject_type', 32);
            $table->unsignedBigInteger('subject_id');
            $table->unsignedBigInteger('previous_id')->nullable();
            $table->foreignId('restored_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('timeline_clips_updated')->default(0);
            $table->timestamp('created_at')->nullable();

            $table->index(['story_workspace_id', 'subject_type', 'subject_id'], 'story_version_restores_subject_index');
        });

        Schema::table('story_scene_assemblies', function (Blueprint $table): void {
            $table->timestamp('selected_at')->nullable()->after('completed_at');
        });

        Schema::table('story_scene_versions', function (Blueprint $table): void {
            $table->timestamp('selected_at')->nullable()->after('reviewed_at');
        });
    }

    public function down(): void
    {
        Schema::table('story_scene_versions', function (Blueprint $table): void {
            $table->dropColumn('selected_at');
        });
        Schema::table('story_scene_assemblies', function (Blueprint $table): void {
            $table->dropColumn('selected_at');
        });
        Schema::dropIfExists('story_version_restores');
    }
};
