<?php

declare(strict_types=1);

namespace App\Story\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $uuid
 * @property string $type
 * @property int $duration_ms
 */
class StoryTimelineTransition extends Model
{
    use HasUuid;

    protected $table = 'story_timeline_transitions';

    protected $fillable = [
        'story_timeline_id',
        'from_clip_id',
        'to_clip_id',
        'type',
        'duration_ms',
    ];

    protected function casts(): array
    {
        return [
            'duration_ms' => 'integer',
        ];
    }

    /** @return BelongsTo<StoryTimelineClip, $this> */
    public function fromClip(): BelongsTo
    {
        return $this->belongsTo(StoryTimelineClip::class, 'from_clip_id');
    }

    /** @return BelongsTo<StoryTimelineClip, $this> */
    public function toClip(): BelongsTo
    {
        return $this->belongsTo(StoryTimelineClip::class, 'to_clip_id');
    }
}
