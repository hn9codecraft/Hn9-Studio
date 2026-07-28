<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Append-only audit trail. Records who (causer/user) did what (action) to
 * which record (polymorphic subject), with an optional before/after payload.
 * Intentionally has no soft delete — audit records are immutable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('activity_logs', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            // Subject the activity relates to (nullable polymorphic).
            $table->nullableMorphs('subject');

            $table->string('action')->index();                 // e.g. project.created
            $table->string('description')->nullable();
            $table->json('properties')->nullable();            // before/after, extra context

            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();

            $table->timestamps();

            $table->index(['user_id', 'action']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activity_logs');
    }
};
