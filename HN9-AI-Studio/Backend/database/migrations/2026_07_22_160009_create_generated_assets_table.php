<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Media output produced by the pipeline — images, videos, voiceovers,
 * thumbnails. The binary file(s) live in `media_files`; this row is the
 * logical asset and its generation metadata.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('generated_assets', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('project_id')
                ->constrained('projects')
                ->cascadeOnDelete();

            $table->foreignId('generated_content_id')
                ->nullable()
                ->constrained('generated_contents')
                ->nullOnDelete();

            $table->foreignId('workflow_run_id')
                ->nullable()
                ->constrained('workflow_runs')
                ->nullOnDelete();

            $table->foreignId('agent_execution_id')
                ->nullable()
                ->constrained('agent_executions')
                ->nullOnDelete();

            $table->string('type')->index();                   // image | video | voice | thumbnail
            $table->string('provider')->nullable();
            $table->string('status')->default('pending')->index();

            $table->text('prompt')->nullable();
            $table->json('metadata')->nullable();              // dimensions, duration, seed, …

            $table->timestamps();
            $table->softDeletes();

            $table->index(['project_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('generated_assets');
    }
};
