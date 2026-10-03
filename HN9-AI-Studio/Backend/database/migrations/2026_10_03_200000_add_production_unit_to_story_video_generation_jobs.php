<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A generation job may belong to one Generation Unit. The unit stays the slot;
 * each attempt is its own job, so the column is not unique.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('story_video_generation_jobs', function (Blueprint $table) {
            $table->foreignId('story_production_unit_id')
                ->nullable()
                ->after('story_scene_id')
                ->constrained('story_production_units')
                ->nullOnDelete();

            $table->index(['story_production_unit_id', 'created_at'], 'story_video_jobs_unit_created_index');
        });
    }

    public function down(): void
    {
        Schema::table('story_video_generation_jobs', function (Blueprint $table) {
            $table->dropIndex('story_video_jobs_unit_created_index');
            $table->dropConstrainedForeignId('story_production_unit_id');
        });
    }
};
