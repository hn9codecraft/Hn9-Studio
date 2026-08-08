<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds the favourite flag the generated-assets API toggles.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('generated_assets', function (Blueprint $table) {
            $table->boolean('is_favorite')->default(false)->index()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('generated_assets', function (Blueprint $table) {
            $table->dropIndex(['is_favorite']);
            $table->dropColumn('is_favorite');
        });
    }
};
