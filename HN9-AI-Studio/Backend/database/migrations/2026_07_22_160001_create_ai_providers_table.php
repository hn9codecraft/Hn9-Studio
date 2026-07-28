<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Registry of AI provider definitions (LLM, image, video, TTS, …).
 *
 * Infrastructure only — this table describes *which* providers exist and
 * their capabilities. It contains no provider integration code.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_providers', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->string('slug')->unique();                 // e.g. openai, anthropic, elevenlabs
            $table->string('name');
            $table->string('category')->index();              // llm | image | video | tts | other
            $table->string('status')->default('active')->index();

            $table->string('base_url')->nullable();
            $table->unsignedInteger('priority')->default(0)->index();

            $table->json('capabilities')->nullable();         // supported models / features
            $table->json('metadata')->nullable();

            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_providers');
    }
};
