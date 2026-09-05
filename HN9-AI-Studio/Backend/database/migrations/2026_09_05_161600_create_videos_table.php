<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Manual studio video requests owned by a project. Separate from generated_assets.
 * output_url stays null until a real provider exists.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('videos', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('project_id')
                ->constrained('projects')
                ->cascadeOnDelete();

            $table->string('title');
            $table->text('prompt');
            $table->text('negative_prompt')->nullable();
            $table->string('aspect_ratio')->default('16:9');
            $table->unsignedSmallInteger('duration')->default(5);
            $table->string('status')->default('draft')->index();
            $table->string('provider')->nullable();
            $table->string('provider_job_id')->nullable();
            $table->text('output_url')->nullable();
            $table->json('metadata')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['project_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('videos');
    }
};
