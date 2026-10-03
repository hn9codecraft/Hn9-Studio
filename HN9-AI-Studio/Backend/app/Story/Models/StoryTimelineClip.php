<?php

declare(strict_types=1);

namespace App\Story\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property string $uuid
 * @property int $position
 * @property string $media_kind
 * @property string $disk
 * @property string $path
 * @property int $in_ms
 * @property int $out_ms
 */
class StoryTimelineClip extends Model
{
    use HasUuid;

    protected $table = 'story_timeline_clips';

    protected $fillable = [
        'story_timeline_id',
        'position',
        'media_kind',
        'story_scene_version_id',
        'story_scene_audio_id',
        'story_scene_assembly_id',
        'disk',
        'path',
        'in_ms',
        'out_ms',
    ];

    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'in_ms' => 'integer',
            'out_ms' => 'integer',
        ];
    }

    /** @return BelongsTo<StoryTimeline, $this> */
    public function timeline(): BelongsTo
    {
        return $this->belongsTo(StoryTimeline::class, 'story_timeline_id');
    }

    /** @return BelongsTo<StorySceneVersion, $this> */
    public function sceneVersion(): BelongsTo
    {
        return $this->belongsTo(StorySceneVersion::class, 'story_scene_version_id');
    }

    /** @return BelongsTo<StorySceneAudio, $this> */
    public function sceneAudio(): BelongsTo
    {
        return $this->belongsTo(StorySceneAudio::class, 'story_scene_audio_id');
    }

    /** @return BelongsTo<StorySceneAssembly, $this> */
    public function sceneAssembly(): BelongsTo
    {
        return $this->belongsTo(StorySceneAssembly::class, 'story_scene_assembly_id');
    }
}
