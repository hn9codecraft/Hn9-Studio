<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Studio project assets — a catalog of files/outputs for a project.
 * Separate from generated_assets, which stores pipeline output.
 * file_url stays null until a real file or URL is supplied.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_assets', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('project_id')
                ->constrained('projects')
                ->cascadeOnDelete();

            $table->string('title');
            $table->string('type')->index();
            $table->string('source')->default('manual');
            $table->string('status')->default('draft')->index();
            $table->text('file_url')->nullable();
            $table->string('mime_type')->nullable();
            $table->text('notes')->nullable();
            $table->json('metadata')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['project_id', 'type']);
            $table->index(['project_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_assets');
    }
};
