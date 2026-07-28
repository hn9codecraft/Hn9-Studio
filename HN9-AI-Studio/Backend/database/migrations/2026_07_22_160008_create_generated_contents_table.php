<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Textual output produced by the pipeline — scripts, captions, blog copy,
 * SEO metadata, subtitles, etc. Binds back to the project and, optionally,
 * the run and agent execution that produced it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('generated_contents', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('project_id')
                ->constrained('projects')
                ->cascadeOnDelete();

            $table->foreignId('workflow_run_id')
                ->nullable()
                ->constrained('workflow_runs')
                ->nullOnDelete();

            $table->foreignId('agent_execution_id')
                ->nullable()
                ->constrained('agent_executions')
                ->nullOnDelete();

            $table->string('type')->index();                   // script | caption | blog | seo | subtitle
            $table->string('channel')->nullable()->index();    // platform
            $table->string('language', 8)->default('en');

            $table->string('title')->nullable();
            $table->longText('body')->nullable();
            $table->json('structured')->nullable();            // structured output shape

            $table->string('status')->default('draft')->index();
            $table->unsignedInteger('version')->default(1);
            $table->json('metadata')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['project_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('generated_contents');
    }
};
