<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One successful generation for one Generation Unit. The unit stays the slot.
 * Review and the unit's selected version are separate from the generation job.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('story_production_unit_versions', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('story_production_unit_id')
                ->constrained('story_production_units')
                ->cascadeOnDelete();

            $table->foreignId('story_video_generation_job_id')
                ->nullable()
                ->constrained('story_video_generation_jobs')
                ->nullOnDelete();

            $table->unsignedInteger('version_number');
            $table->string('review_status', 32)->default('pending_review')->index();
            $table->text('review_comment')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();

            $table->string('provider_key')->nullable();
            $table->string('model_key')->nullable();
            $table->string('capability')->nullable();
            $table->unsignedInteger('requested_duration_seconds');
            $table->decimal('produced_duration_seconds', 8, 2);
            $table->string('disk');
            $table->string('path');
            $table->string('mime')->nullable();
            $table->unsignedBigInteger('size')->nullable();
            $table->json('snapshot')->nullable();

            $table->timestamps();

            $table->unique(['story_production_unit_id', 'version_number'], 'story_unit_versions_unit_number_unique');
            $table->unique('story_video_generation_job_id', 'story_unit_versions_job_unique');
            $table->index(['story_production_unit_id', 'review_status'], 'story_unit_versions_unit_review_index');
        });

        Schema::table('story_production_units', function (Blueprint $table) {
            $table->foreignId('selected_version_id')
                ->nullable()
                ->constrained('story_production_unit_versions')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('story_production_units', function (Blueprint $table) {
            $table->dropConstrainedForeignId('selected_version_id');
        });

        Schema::dropIfExists('story_production_unit_versions');
    }
};
