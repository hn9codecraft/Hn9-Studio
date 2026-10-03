<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A timeline video clip may point at the finished scene assembly instead of an
 * older scene-level video. The assembly file stays the source.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('story_timeline_clips', function (Blueprint $table) {
            $table->foreignId('story_scene_assembly_id')
                ->nullable()
                ->after('story_scene_audio_id')
                ->constrained('story_scene_assemblies')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('story_timeline_clips', function (Blueprint $table) {
            $table->dropConstrainedForeignId('story_scene_assembly_id');
        });
    }
};
