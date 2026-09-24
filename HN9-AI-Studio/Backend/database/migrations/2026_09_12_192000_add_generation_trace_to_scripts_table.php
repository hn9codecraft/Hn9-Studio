<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Links studio scripts to pipeline generated_contents without replacing
 * manual CRUD. Existing rows remain source=manual with no generation parent.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('scripts', function (Blueprint $table) {
            $table->string('source')->default('manual')->after('status');
            $table->foreignId('generated_content_id')
                ->nullable()
                ->after('source')
                ->constrained('generated_contents')
                ->nullOnDelete();
            $table->foreignId('parent_script_id')
                ->nullable()
                ->after('generated_content_id')
                ->constrained('scripts')
                ->nullOnDelete();
            $table->json('generation')->nullable()->after('parent_script_id');

            $table->unique('generated_content_id');
            $table->index(['project_id', 'source']);
            $table->index('parent_script_id');
        });
    }

    public function down(): void
    {
        Schema::table('scripts', function (Blueprint $table) {
            $table->dropUnique(['generated_content_id']);
            $table->dropIndex(['project_id', 'source']);
            $table->dropConstrainedForeignId('parent_script_id');
            $table->dropConstrainedForeignId('generated_content_id');
            $table->dropColumn(['source', 'generation']);
        });
    }
};
