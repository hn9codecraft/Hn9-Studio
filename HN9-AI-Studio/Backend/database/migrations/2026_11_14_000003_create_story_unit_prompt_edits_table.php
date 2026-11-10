<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A saved replacement for one generation unit's automatic scene prompt.
 * A new production-plan revision creates new unit rows, so edits are not copied.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('story_unit_prompt_edits', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('story_production_unit_id')->unique()->constrained('story_production_units')->cascadeOnDelete();
            $table->text('prompt');
            $table->string('base_hash', 64);
            $table->foreignId('edited_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('story_unit_prompt_edits');
    }
};
