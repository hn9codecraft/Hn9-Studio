<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('provider_settings', function (Blueprint $table): void {
            $table->uuid('uuid')->nullable()->after('id')->unique();
        });
    }

    public function down(): void
    {
        Schema::table('provider_settings', function (Blueprint $table): void {
            $table->dropColumn('uuid');
        });
    }
};
