<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A single execution of the content pipeline for a project. The `uuid` is the
 * pipeline's requestId; `context` carries the shared context between stages.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workflow_runs', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();                    // requestId

            $table->foreignId('project_id')
                ->constrained('projects')
                ->cascadeOnDelete();

            $table->foreignId('user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->string('workflow_key')->index();           // which workflow definition
            $table->string('status')->default('pending')->index();
            $table->string('current_stage')->nullable();

            $table->unsignedInteger('total_steps')->nullable();
            $table->unsignedInteger('completed_steps')->default(0);

            $table->json('context')->nullable();               // shared pipeline context
            $table->text('error')->nullable();

            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->unsignedBigInteger('duration_ms')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['project_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workflow_runs');
    }
};
