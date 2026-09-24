<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Links studio videos to real generation output and records the M10.3 review trail.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('videos', function (Blueprint $table) {
            $table->string('source')->default('manual')->after('status');
            $table->foreignId('script_id')
                ->nullable()
                ->after('source')
                ->constrained('scripts')
                ->nullOnDelete();
            $table->foreignId('image_id')
                ->nullable()
                ->after('script_id')
                ->constrained('images')
                ->nullOnDelete();
            $table->foreignId('parent_video_id')
                ->nullable()
                ->after('image_id')
                ->constrained('videos')
                ->nullOnDelete();
            $table->foreignId('generated_asset_id')
                ->nullable()
                ->after('parent_video_id')
                ->constrained('generated_assets')
                ->nullOnDelete();
            $table->json('generation')->nullable()->after('metadata');

            $table->index(['project_id', 'source']);
            $table->index('parent_video_id');
        });

        Schema::create('video_review_events', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('video_id')
                ->constrained('videos')
                ->cascadeOnDelete();

            $table->foreignId('user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->string('action');
            $table->text('comment')->nullable();
            $table->string('from_status');
            $table->string('to_status');
            $table->timestamp('created_at')->useCurrent();

            $table->index(['video_id', 'created_at']);
            $table->index(['video_id', 'action']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('video_review_events');

        Schema::table('videos', function (Blueprint $table) {
            $table->dropIndex(['project_id', 'source']);
            $table->dropIndex(['parent_video_id']);
            $table->dropConstrainedForeignId('generated_asset_id');
            $table->dropConstrainedForeignId('parent_video_id');
            $table->dropConstrainedForeignId('image_id');
            $table->dropConstrainedForeignId('script_id');
            $table->dropColumn(['source', 'generation']);
        });
    }
};
