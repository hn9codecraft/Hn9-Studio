<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Scene and reel review versions and comments. No media binaries.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('story_scene_versions', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('story_scene_id')->constrained('story_scenes')->cascadeOnDelete();
            $table->unsignedBigInteger('parent_version_id')->nullable()->index();
            $table->unsignedInteger('version');
            $table->string('status')->default('draft')->index();
            $table->string('title')->nullable();
            $table->text('story')->nullable();
            $table->text('visual_prompt')->nullable();
            $table->json('continuity')->nullable();
            $table->text('review_comment')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['story_scene_id', 'version']);
        });

        Schema::create('story_scene_comments', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('story_scene_version_id')->constrained('story_scene_versions')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->text('body');
            $table->timestamps();
        });

        Schema::create('story_reel_versions', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('story_reel_id')->constrained('story_reels')->cascadeOnDelete();
            $table->unsignedBigInteger('parent_version_id')->nullable()->index();
            $table->unsignedInteger('version');
            $table->string('status')->default('draft')->index();
            $table->string('title')->nullable();
            $table->text('description')->nullable();
            $table->text('review_comment')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['story_reel_id', 'version']);
        });

        Schema::create('story_reel_comments', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('story_reel_version_id')->constrained('story_reel_versions')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->text('body');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('story_reel_comments');
        Schema::dropIfExists('story_reel_versions');
        Schema::dropIfExists('story_scene_comments');
        Schema::dropIfExists('story_scene_versions');
    }
};
