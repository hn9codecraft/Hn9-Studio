<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lock history. The active lock row is deleted on unlock; these rows stay.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('story_character_lock_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('story_character_id')->constrained('story_characters')->cascadeOnDelete();
            $table->string('action', 16);
            $table->string('identity_hash', 64);
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->index(['story_character_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('story_character_lock_events');
    }
};
