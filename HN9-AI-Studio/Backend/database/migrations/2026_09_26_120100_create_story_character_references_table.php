<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Versioned character references. Private storage paths stay off the public API.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('story_character_references', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('story_character_id')
                ->constrained('story_characters')
                ->cascadeOnDelete();

            $table->string('source')->index();
            $table->string('role')->default('primary')->index();
            $table->unsignedInteger('version');
            $table->string('status')->default('draft')->index();

            $table->string('disk')->default('images');
            $table->string('path');
            $table->string('original_filename')->nullable();
            $table->string('mime_type');
            $table->string('extension', 16);
            $table->unsignedBigInteger('size');
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->string('checksum', 64)->nullable();

            $table->text('prompt')->nullable();
            $table->string('provider')->nullable();
            $table->string('model')->nullable();
            $table->json('generation')->nullable();

            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('review_comment')->nullable();
            $table->timestamps();

            $table->unique(['story_character_id', 'version']);
            $table->index(['story_character_id', 'status']);
            $table->index(['story_character_id', 'role', 'status']);
        });

        Schema::table('story_characters', function (Blueprint $table) {
            $table->foreign('approved_reference_id')
                ->references('id')
                ->on('story_character_references')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('story_characters', function (Blueprint $table) {
            $table->dropForeign(['approved_reference_id']);
        });

        Schema::dropIfExists('story_character_references');
    }
};
