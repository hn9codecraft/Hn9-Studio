<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('story_scene_audios', function (Blueprint $table): void {
            $table->string('language', 10)->nullable()->after('prompt');
            $table->string('voice_model', 100)->nullable()->after('language');
        });
    }

    public function down(): void
    {
        Schema::table('story_scene_audios', function (Blueprint $table): void {
            $table->dropColumn(['language', 'voice_model']);
        });
    }
};
