<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Append-only approval/review history for studio scripts (M10.3).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('script_review_events', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('script_id')
                ->constrained('scripts')
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

            $table->index(['script_id', 'created_at']);
            $table->index(['script_id', 'action']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('script_review_events');
    }
};
