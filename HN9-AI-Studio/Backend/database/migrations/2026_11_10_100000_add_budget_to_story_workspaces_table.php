<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('story_workspaces', function (Blueprint $table) {
            $table->decimal('budget_amount', 12, 2)->nullable();
            $table->string('budget_currency', 3)->default('INR');
        });
    }

    public function down(): void
    {
        Schema::table('story_workspaces', function (Blueprint $table) {
            $table->dropColumn(['budget_amount', 'budget_currency']);
        });
    }
};
