<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Links studio images to real generation output and records the M10.3 review trail.
 * Existing manual image rows stay source=manual with no parent or asset.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('images', function (Blueprint $table) {
            $table->string('source')->default('manual')->after('status');
            $table->foreignId('script_id')
                ->nullable()
                ->after('source')
                ->constrained('scripts')
                ->nullOnDelete();
            $table->foreignId('parent_image_id')
                ->nullable()
                ->after('script_id')
                ->constrained('images')
                ->nullOnDelete();
            $table->foreignId('generated_content_id')
                ->nullable()
                ->after('parent_image_id')
                ->constrained('generated_contents')
                ->nullOnDelete();
            $table->foreignId('generated_asset_id')
                ->nullable()
                ->after('generated_content_id')
                ->constrained('generated_assets')
                ->nullOnDelete();
            $table->json('generation')->nullable()->after('metadata');

            $table->index(['project_id', 'source']);
        });

        Schema::create('image_review_events', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('image_id')
                ->constrained('images')
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

            $table->index(['image_id', 'created_at']);
            $table->index(['image_id', 'action']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('image_review_events');

        Schema::table('images', function (Blueprint $table) {
            $table->dropIndex(['project_id', 'source']);
            $table->dropConstrainedForeignId('generated_asset_id');
            $table->dropConstrainedForeignId('generated_content_id');
            $table->dropConstrainedForeignId('parent_image_id');
            $table->dropConstrainedForeignId('script_id');
            $table->dropColumn(['source', 'generation']);
        });
    }
};
