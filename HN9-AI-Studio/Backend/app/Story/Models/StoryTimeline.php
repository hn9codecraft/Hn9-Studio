<?php

declare(strict_types=1);

namespace App\Story\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StoryTimeline extends Model
{
    use HasUuid;

    protected $table = 'story_timelines';

    protected $fillable = [
        'story_reel_id',
    ];

    /** @return BelongsTo<StoryReel, $this> */
    public function reel(): BelongsTo
    {
        return $this->belongsTo(StoryReel::class, 'story_reel_id');
    }

    /** @return HasMany<StoryTimelineClip, $this> */
    public function clips(): HasMany
    {
        return $this->hasMany(StoryTimelineClip::class, 'story_timeline_id')->orderBy('position');
    }

    /** @return HasMany<StoryTimelineTransition, $this> */
    public function transitions(): HasMany
    {
        return $this->hasMany(StoryTimelineTransition::class, 'story_timeline_id');
    }
}
