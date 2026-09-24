<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Persists real project export packages. A row is completed only after the ZIP
 * exists on the private exports disk.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exports', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('user_id')
                ->constrained('users')
                ->cascadeOnDelete();

            $table->foreignId('project_id')
                ->constrained('projects')
                ->cascadeOnDelete();

            $table->string('status')->index();
            $table->string('disk')->default('exports');
            $table->string('path')->nullable();
            $table->string('filename')->nullable();
            $table->unsignedBigInteger('size')->nullable();
            $table->string('fingerprint')->index();
            $table->text('error')->nullable();
            $table->json('manifest')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['project_id', 'status']);
            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exports');
    }
};
