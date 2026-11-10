<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('story_final_renders', function (Blueprint $table): void {
            $table->foreignId('requested_by')->nullable()->after('story_reel_id')->constrained('users')->nullOnDelete();
            $table->timestamp('queued_at')->nullable()->after('error_message');
            $table->timestamp('started_at')->nullable()->after('queued_at');
        });
    }

    public function down(): void
    {
        Schema::table('story_final_renders', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('requested_by');
            $table->dropColumn(['queued_at', 'started_at']);
        });
    }
};
