<?php

declare(strict_types=1);

namespace App\Story\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $story_scene_audio_id
 * @property string|null $checksum
 * @property int $duration_ms
 * @property string $alignment_source
 * @property string|null $alignment_disk
 * @property string|null $alignment_path
 */
class StoryAudioTiming extends Model
{
    protected $table = 'story_audio_timings';

    protected $fillable = [
        'story_scene_audio_id',
        'checksum',
        'duration_ms',
        'alignment_source',
        'alignment_disk',
        'alignment_path',
        'measured_at',
    ];

    protected function casts(): array
    {
        return [
            'duration_ms' => 'integer',
            'measured_at' => 'datetime',
        ];
    }

    public function audio(): BelongsTo
    {
        return $this->belongsTo(StorySceneAudio::class, 'story_scene_audio_id');
    }
}
