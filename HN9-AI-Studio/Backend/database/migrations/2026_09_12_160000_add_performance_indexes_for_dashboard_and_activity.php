<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Indexes for documented hot paths: activity ordering, usage/cost date filters,
 * and the dashboard recent-projects lookup (user_id + updated_at).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('activity_logs', function (Blueprint $table): void {
            $table->index('created_at');
        });

        Schema::table('prompt_executions', function (Blueprint $table): void {
            $table->index('created_at');
        });

        Schema::table('projects', function (Blueprint $table): void {
            $table->index(['user_id', 'updated_at']);
        });
    }

    public function down(): void
    {
        Schema::table('activity_logs', function (Blueprint $table): void {
            $table->dropIndex(['created_at']);
        });

        Schema::table('prompt_executions', function (Blueprint $table): void {
            $table->dropIndex(['created_at']);
        });

        Schema::table('projects', function (Blueprint $table): void {
            $table->dropIndex(['user_id', 'updated_at']);
        });
    }
};
