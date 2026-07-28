<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Physical file records. Polymorphic so any model (project, generated asset,
 * generated content, user avatar, …) can own one or more files across the
 * configured storage disks.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('media_files', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            // Owning model (mediable_type + mediable_id), indexed together.
            $table->morphs('mediable');

            $table->string('disk')->default('local');
            $table->string('path');
            $table->string('original_name')->nullable();
            $table->string('mime_type')->nullable()->index();
            $table->string('extension', 32)->nullable();
            $table->unsignedBigInteger('size')->nullable();
            $table->string('checksum')->nullable()->index();   // dedupe / integrity
            $table->string('collection')->nullable()->index(); // images | videos | voice | exports

            $table->json('meta')->nullable();

            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('media_files');
    }
};
