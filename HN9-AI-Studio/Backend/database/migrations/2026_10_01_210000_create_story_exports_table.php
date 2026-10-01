<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Story final package exports. A row is completed only after the ZIP exists on the private exports disk.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('story_exports', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $table->foreignId('story_reel_id')->constrained('story_reels')->cascadeOnDelete();
            $table->foreignId('story_final_render_id')->constrained('story_final_renders')->cascadeOnDelete();
            $table->string('status', 32)->index();
            $table->string('disk')->default('exports');
            $table->string('path')->nullable();
            $table->string('filename')->nullable();
            $table->unsignedBigInteger('size')->nullable();
            $table->text('error')->nullable();
            $table->json('manifest')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['story_final_render_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('story_exports');
    }
};
