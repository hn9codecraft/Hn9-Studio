<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Scene sound versions and review. Each row stays one version of one role's
 * sound; the selected row is the approved version the timeline uses.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('story_scene_audios', function (Blueprint $table) {
            $table->unsignedInteger('version_number')->default(1)->after('role');
            $table->foreignId('parent_audio_id')
                ->nullable()
                ->after('version_number')
                ->constrained('story_scene_audios')
                ->nullOnDelete();
            $table->string('review_status', 32)->default('draft')->after('status')->index();
            $table->text('review_comment')->nullable()->after('review_status');
            $table->timestamp('reviewed_at')->nullable()->after('review_comment');
            $table->foreignId('reviewed_by')
                ->nullable()
                ->after('reviewed_at')
                ->constrained('users')
                ->nullOnDelete();
            $table->timestamp('selected_at')->nullable()->after('reviewed_by');
        });

        Schema::table('story_scene_audios', function (Blueprint $table) {
            $table->text('prompt')->nullable()->change();
        });

        $this->backfill();
    }

    public function down(): void
    {
        Schema::table('story_scene_audios', function (Blueprint $table) {
            $table->dropConstrainedForeignId('reviewed_by');
            $table->dropConstrainedForeignId('parent_audio_id');
            $table->dropColumn(['version_number', 'review_status', 'review_comment', 'reviewed_at', 'selected_at']);
        });
    }

    /**
     * Numbers existing rows per scene and role. Sound already placed on a timeline
     * was in production use, so it is recorded as the approved, selected version;
     * other finished sound waits for review.
     */
    private function backfill(): void
    {
        $onTimeline = DB::table('story_timeline_clips')
            ->whereNotNull('story_scene_audio_id')
            ->pluck('story_scene_audio_id')
            ->all();

        $counters = [];
        DB::table('story_scene_audios')->orderBy('id')->each(function (object $row) use (&$counters, $onTimeline): void {
            $group = $row->story_scene_id.':'.$row->role;
            $counters[$group] = ($counters[$group] ?? 0) + 1;
            $finished = $row->status === 'completed' && is_string($row->path) && $row->path !== '';
            $approved = $finished && in_array($row->id, $onTimeline, false);

            DB::table('story_scene_audios')->where('id', $row->id)->update([
                'version_number' => $counters[$group],
                'review_status' => $approved ? 'approved' : ($finished ? 'pending_review' : 'draft'),
                'selected_at' => $approved ? $row->updated_at : null,
            ]);
        });
    }
};
