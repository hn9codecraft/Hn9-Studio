<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A scheduled or completed publishing action for a piece of content/asset to
 * an external channel. Tracks scheduling, external identifiers and outcome.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('publish_jobs', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('project_id')
                ->constrained('projects')
                ->cascadeOnDelete();

            $table->foreignId('generated_content_id')
                ->nullable()
                ->constrained('generated_contents')
                ->nullOnDelete();

            $table->foreignId('generated_asset_id')
                ->nullable()
                ->constrained('generated_assets')
                ->nullOnDelete();

            $table->foreignId('user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->string('channel')->index();                // instagram | youtube | linkedin | …
            $table->string('status')->default('queued')->index();

            $table->timestamp('scheduled_at')->nullable()->index();
            $table->timestamp('published_at')->nullable();

            $table->string('external_id')->nullable();         // platform post id
            $table->string('external_url')->nullable();

            $table->json('payload')->nullable();
            $table->json('response')->nullable();
            $table->text('error')->nullable();
            $table->unsignedInteger('attempts')->default(0);

            $table->timestamps();
            $table->softDeletes();

            $table->index(['project_id', 'status']);
            $table->index(['channel', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('publish_jobs');
    }
};
