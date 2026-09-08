<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Honest cost provenance on the existing prompt-execution ledger.
 * cost stays nullable; cost_source is set only when a real settled charge or
 * a configured (non-empty) pricing rule produced the amount.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('prompt_executions', function (Blueprint $table) {
            $table->string('currency', 8)->nullable()->after('cost');
            $table->string('cost_source')->nullable()->after('currency');
        });
    }

    public function down(): void
    {
        Schema::table('prompt_executions', function (Blueprint $table) {
            $table->dropColumn(['currency', 'cost_source']);
        });
    }
};
