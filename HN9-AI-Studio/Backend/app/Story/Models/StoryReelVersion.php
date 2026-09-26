<?php

declare(strict_types=1);

namespace App\Story\Models;

use App\Models\Concerns\HasUuid;
use App\Models\User;
use App\Story\Enums\StoryReviewStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $uuid
 * @property int $story_reel_id
 * @property int $version
 * @property string $status
 */
class StoryReelVersion extends Model
{
    use HasUuid;

    protected $table = 'story_reel_versions';

    protected $fillable = [
        'story_reel_id',
        'parent_version_id',
        'version',
        'status',
        'title',
        'description',
        'review_comment',
        'submitted_at',
        'reviewed_at',
        'reviewed_by',
    ];

    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'submitted_at' => 'datetime',
            'reviewed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<StoryReel, $this> */
    public function reel(): BelongsTo
    {
        return $this->belongsTo(StoryReel::class, 'story_reel_id');
    }

    /** @return HasMany<StoryReelComment, $this> */
    public function comments(): HasMany
    {
        return $this->hasMany(StoryReelComment::class, 'story_reel_version_id');
    }

    /** @return BelongsTo<User, $this> */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function statusEnum(): StoryReviewStatus
    {
        return StoryReviewStatus::tryFrom((string) $this->status) ?? StoryReviewStatus::Draft;
    }
}
