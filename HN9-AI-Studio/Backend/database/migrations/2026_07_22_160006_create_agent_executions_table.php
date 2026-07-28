<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A single agent job within a workflow run (the pipeline's stepId). Tracks
 * status, attempts, timing and token/cost accounting for one agent.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent_executions', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();                    // stepId

            $table->foreignId('workflow_run_id')
                ->constrained('workflow_runs')
                ->cascadeOnDelete();

            $table->foreignId('ai_provider_id')
                ->nullable()
                ->constrained('ai_providers')
                ->nullOnDelete();

            $table->string('agent_key')->index();              // planner | research | seo | …
            $table->string('agent_version')->nullable();
            $table->string('status')->default('pending')->index();
            $table->unsignedInteger('attempt')->default(1);

            $table->json('input')->nullable();
            $table->json('output')->nullable();
            $table->text('error')->nullable();

            $table->unsignedBigInteger('tokens_used')->nullable();
            $table->decimal('cost', 12, 6)->nullable();

            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->unsignedBigInteger('duration_ms')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['workflow_run_id', 'agent_key']);
            $table->index(['workflow_run_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_executions');
    }
};
