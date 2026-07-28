<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-provider configuration as environment-scoped key/value pairs.
 *
 * Secret values (API keys, tokens) are flagged and encrypted at the model
 * layer. No credential values are stored by this migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('provider_settings', function (Blueprint $table) {
            $table->id();

            $table->foreignId('ai_provider_id')
                ->constrained('ai_providers')
                ->cascadeOnDelete();

            $table->string('key');
            $table->text('value')->nullable();
            $table->boolean('is_secret')->default(false);
            $table->string('environment')->default('production')->index();

            $table->timestamps();

            // One value per key, per provider, per environment.
            $table->unique(['ai_provider_id', 'key', 'environment'], 'provider_settings_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('provider_settings');
    }
};
