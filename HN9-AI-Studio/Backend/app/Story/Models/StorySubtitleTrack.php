<?php

declare(strict_types=1);

namespace App\Story\Models;

use App\Models\Concerns\HasUuid;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property string $uuid
 * @property int $story_workspace_id
 * @property int $story_reel_id
 * @property int $source_render_id
 * @property int|null $burned_render_id
 * @property string $language
 * @property string $timing_mode
 * @property int $cue_count
 * @property string $cues_hash
 * @property list<array<string, mixed>> $cues
 * @property string $disk
 * @property string $srt_path
 * @property string $vtt_path
 */
class StorySubtitleTrack extends Model
{
    use HasUuid;

    protected $table = 'story_subtitle_tracks';

    protected $fillable = [
        'story_workspace_id',
        'story_reel_id',
        'source_render_id',
        'burned_render_id',
        'language',
        'timing_mode',
        'cue_count',
        'cues_hash',
        'cues',
        'disk',
        'srt_path',
        'vtt_path',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'cues' => 'array',
            'cue_count' => 'integer',
        ];
    }

    public function sourceRender(): BelongsTo
    {
        return $this->belongsTo(StoryFinalRender::class, 'source_render_id');
    }

    public function burnedRender(): BelongsTo
    {
        return $this->belongsTo(StoryFinalRender::class, 'burned_render_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
