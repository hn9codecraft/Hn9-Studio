<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A request brief / runtime-variable set submitted for a project. Maps to the
 * Prompt Engine's "runtime variables" (topic, goal, platform, language, …).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_inputs', function (Blueprint $table) {
            $table->id();

            $table->foreignId('project_id')
                ->constrained('projects')
                ->cascadeOnDelete();

            $table->foreignId('user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->string('type')->default('brief')->index();
            $table->string('deliverable_type')->nullable()->index();
            $table->string('platform')->nullable()->index();
            $table->string('language', 8)->default('en');

            $table->string('topic')->nullable();
            $table->text('goal')->nullable();

            $table->json('payload')->nullable();               // full bound runtime variables
            $table->string('source')->nullable();              // api | ui | import

            $table->timestamps();
            $table->softDeletes();

            $table->index(['project_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_inputs');
    }
};
