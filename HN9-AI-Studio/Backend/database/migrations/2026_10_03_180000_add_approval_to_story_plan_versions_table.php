<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Approval of a finished Story Plan Version: the gate into production planning.
 * Generation status stays in `status`; approval is recorded beside it, like scene reviews.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('story_plan_versions', function (Blueprint $table) {
            $table->timestamp('approved_at')->nullable()->after('error_message');
            $table->foreignId('approved_by')
                ->nullable()
                ->after('approved_at')
                ->constrained('users')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('story_plan_versions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('approved_by');
            $table->dropColumn('approved_at');
        });
    }
};
