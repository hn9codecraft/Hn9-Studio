<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One active look lock per character. Unlocking removes the row after the event is stored.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('story_character_locks', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('story_workspace_id')->constrained('story_workspaces')->cascadeOnDelete();
            $table->foreignId('story_character_id')->unique()->constrained('story_characters')->cascadeOnDelete();
            $table->foreignId('story_character_reference_id')->constrained('story_character_references')->restrictOnDelete();
            $table->text('identity_text');
            $table->string('identity_hash', 64);
            $table->json('locked_fields');
            $table->foreignId('locked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('locked_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('story_character_locks');
    }
};
