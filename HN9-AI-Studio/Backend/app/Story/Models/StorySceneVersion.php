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
 * @property int $story_scene_id
 * @property int|null $parent_version_id
 * @property int $version
 * @property string $status
 */
class StorySceneVersion extends Model
{
    use HasUuid;

    protected $table = 'story_scene_versions';

    protected $fillable = [
        'story_scene_id',
        'parent_version_id',
        'version',
        'status',
        'title',
        'story',
        'visual_prompt',
        'continuity',
        'review_comment',
        'submitted_at',
        'reviewed_at',
        'reviewed_by',
    ];

    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'continuity' => 'array',
            'submitted_at' => 'datetime',
            'reviewed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<StoryScene, $this> */
    public function scene(): BelongsTo
    {
        return $this->belongsTo(StoryScene::class, 'story_scene_id');
    }

    /** @return BelongsTo<self, $this> */
    public function parentVersion(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_version_id');
    }

    /** @return HasMany<StorySceneComment, $this> */
    public function comments(): HasMany
    {
        return $this->hasMany(StorySceneComment::class, 'story_scene_version_id');
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
