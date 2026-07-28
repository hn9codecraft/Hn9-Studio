<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A single Prompt Engine assembly + model call performed by an agent. Stores
 * the rendered prompt, bound variables, the response and token/cost telemetry.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('prompt_executions', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('agent_execution_id')
                ->constrained('agent_executions')
                ->cascadeOnDelete();

            $table->foreignId('ai_provider_id')
                ->nullable()
                ->constrained('ai_providers')
                ->nullOnDelete();

            $table->string('template_key')->index();           // which template was used
            $table->string('template_version')->nullable();
            $table->string('model')->nullable();
            $table->string('status')->default('pending')->index();

            $table->longText('rendered_prompt')->nullable();
            $table->json('variables')->nullable();             // bound {{...}} values
            $table->longText('response')->nullable();

            $table->unsignedBigInteger('prompt_tokens')->nullable();
            $table->unsignedBigInteger('completion_tokens')->nullable();
            $table->unsignedBigInteger('total_tokens')->nullable();
            $table->decimal('cost', 12, 6)->nullable();
            $table->unsignedBigInteger('latency_ms')->nullable();

            $table->text('error')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['agent_execution_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('prompt_executions');
    }
};
