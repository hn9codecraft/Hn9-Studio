<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Production plans: an immutable record of how the scenes of one Story Plan Version
 * are split into generation units. Upstream story records are restricted, never
 * cascaded, so production history cannot disappear with an editable record.
 * A plan's own scene rows and units belong to it and go only with the plan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('story_production_plans', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('story_workspace_id')->constrained('story_workspaces')->restrictOnDelete();
            $table->foreignId('story_plan_id')->constrained('story_plans')->restrictOnDelete();
            $table->foreignId('story_plan_version_id')->constrained('story_plan_versions')->restrictOnDelete();
            $table->foreignId('story_reel_id')->constrained('story_reels')->restrictOnDelete();
            $table->foreignId('previous_plan_id')->nullable()->unique()->constrained('story_production_plans')->restrictOnDelete();

            $table->unsignedInteger('revision');
            $table->string('status')->default('active');
            // Equals story_plan_id while the plan is current and NULL once superseded:
            // the unique index allows only one current plan per story plan.
            $table->unsignedBigInteger('current_for_story_plan_id')->nullable()->unique();
            $table->unsignedSmallInteger('unit_seconds');
            $table->unsignedInteger('total_duration_seconds');

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('superseded_at')->nullable();
            $table->timestamps();

            $table->unique(['story_plan_id', 'revision']);
            $table->index(['story_workspace_id', 'status']);
        });

        Schema::create('story_production_plan_scenes', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('story_production_plan_id')->constrained('story_production_plans')->cascadeOnDelete();
            $table->foreignId('story_scene_id')->constrained('story_scenes')->restrictOnDelete();
            $table->foreignId('story_scene_version_id')->nullable()->constrained('story_scene_versions')->restrictOnDelete();

            $table->unsignedInteger('sequence');
            $table->unsignedInteger('start_second');
            $table->unsignedInteger('duration_seconds');
            $table->timestamps();

            $table->unique(['story_production_plan_id', 'sequence'], 'story_production_plan_scenes_plan_sequence_unique');
            $table->unique(['story_production_plan_id', 'story_scene_id'], 'story_production_plan_scenes_plan_scene_unique');
            $table->index('story_scene_id');
        });

        Schema::create('story_production_units', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('story_production_plan_scene_id')->constrained('story_production_plan_scenes')->cascadeOnDelete();

            $table->unsignedInteger('sequence');
            $table->unsignedInteger('start_second');
            $table->unsignedSmallInteger('duration_seconds');
            $table->timestamps();

            $table->unique(['story_production_plan_scene_id', 'sequence'], 'story_production_units_scene_sequence_unique');
            $table->unique(['story_production_plan_scene_id', 'start_second'], 'story_production_units_scene_start_unique');
        });

        // SQLite cannot add CHECK constraints to an existing table; the service validates the same rules.
        if (in_array(DB::getDriverName(), ['mysql', 'mariadb', 'pgsql'], true)) {
            DB::statement('ALTER TABLE story_production_plans ADD CONSTRAINT story_production_plans_positive_check CHECK (revision > 0 AND unit_seconds > 0 AND total_duration_seconds > 0)');
            DB::statement('ALTER TABLE story_production_plan_scenes ADD CONSTRAINT story_production_plan_scenes_positive_check CHECK (sequence > 0 AND duration_seconds > 0)');
            DB::statement('ALTER TABLE story_production_units ADD CONSTRAINT story_production_units_positive_check CHECK (sequence > 0 AND duration_seconds > 0)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('story_production_units');
        Schema::dropIfExists('story_production_plan_scenes');
        Schema::dropIfExists('story_production_plans');
    }
};
