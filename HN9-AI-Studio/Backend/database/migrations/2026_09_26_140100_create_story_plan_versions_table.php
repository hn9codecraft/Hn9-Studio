<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Versioned story planner outputs. No M11.5 runtime scene rows.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('story_plan_versions', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('story_plan_id')
                ->constrained('story_plans')
                ->cascadeOnDelete();

            $table->unsignedInteger('version');
            $table->string('status')->default('generating')->index();

            $table->json('input')->nullable();
            $table->text('instruction')->nullable();
            $table->text('master_story')->nullable();
            $table->json('plan')->nullable();
            $table->string('remainder_strategy')->nullable();

            $table->string('provider')->nullable();
            $table->string('model')->nullable();
            $table->json('generation')->nullable();
            $table->text('error_message')->nullable();

            $table->timestamps();

            $table->unique(['story_plan_id', 'version']);
            $table->index(['story_plan_id', 'status']);
        });

        Schema::table('story_plans', function (Blueprint $table) {
            $table->foreign('current_version_id')
                ->references('id')
                ->on('story_plan_versions')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('story_plans', function (Blueprint $table) {
            $table->dropForeign(['current_version_id']);
        });

        Schema::dropIfExists('story_plan_versions');
    }
};
