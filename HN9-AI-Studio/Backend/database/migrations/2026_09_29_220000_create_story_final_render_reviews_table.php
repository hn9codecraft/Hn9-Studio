<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('story_final_renders', function (Blueprint $table): void {
            $table->string('review_status', 32)->default('draft')->after('status');
        });

        Schema::create('story_final_render_reviews', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('story_final_render_id')->constrained('story_final_renders')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('action', 32);
            $table->text('comment')->nullable();
            $table->string('target_kind', 32)->nullable();
            $table->uuid('target_id')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('story_final_render_reviews');
        Schema::table('story_final_renders', function (Blueprint $table): void {
            $table->dropColumn('review_status');
        });
    }
};
