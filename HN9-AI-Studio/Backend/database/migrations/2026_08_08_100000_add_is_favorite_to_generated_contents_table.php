<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds the favourite flag the generated-content API toggles.
 *
 * Additive only: the original create migration is left untouched. The column is
 * indexed because it is an exposed list filter.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('generated_contents', function (Blueprint $table) {
            $table->boolean('is_favorite')->default(false)->index()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('generated_contents', function (Blueprint $table) {
            $table->dropIndex(['is_favorite']);
            $table->dropColumn('is_favorite');
        });
    }
};
