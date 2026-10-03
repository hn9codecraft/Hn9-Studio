<?php

declare(strict_types=1);

namespace App\Story\Models;

use App\Models\Concerns\HasUuid;
use App\Models\User;
use App\Story\Enums\StoryAudioRole;
use App\Story\Enums\StoryReviewStatus;
use App\Story\Enums\StoryVideoJobStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One version of one role's sound for a scene.
 *
 * @property int $id
 * @property string $uuid
 * @property int $story_workspace_id
 * @property int $story_scene_id
 * @property int|null $story_scene_version_id
 * @property int|null $story_video_generation_job_id
 * @property string $role
 * @property int $version_number
 * @property int|null $parent_audio_id
 * @property string $status
 * @property string $review_status
 * @property string|null $review_comment
 * @property \Illuminate\Support\Carbon|null $reviewed_at
 * @property int|null $reviewed_by
 * @property \Illuminate\Support\Carbon|null $selected_at
 * @property string|null $disk
 * @property string|null $path
 */
class StorySceneAudio extends Model
{
    use HasUuid;

    protected $table = 'story_scene_audios';

    protected $fillable = [
        'story_workspace_id',
        'story_scene_id',
        'story_scene_version_id',
        'story_video_generation_job_id',
        'role',
        'version_number',
        'parent_audio_id',
        'status',
        'review_status',
        'review_comment',
        'reviewed_at',
        'reviewed_by',
        'selected_at',
        'prompt',
        'idempotency_key',
        'disk',
        'path',
        'mime',
        'size',
        'checksum',
        'error_code',
        'error_message',
    ];

    protected function casts(): array
    {
        return [
            'size' => 'integer',
            'version_number' => 'integer',
            'reviewed_at' => 'datetime',
            'selected_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<StoryWorkspace, $this> */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(StoryWorkspace::class, 'story_workspace_id');
    }

    /** @return BelongsTo<StoryScene, $this> */
    public function scene(): BelongsTo
    {
        return $this->belongsTo(StoryScene::class, 'story_scene_id');
    }

    /** @return BelongsTo<StorySceneVersion, $this> */
    public function version(): BelongsTo
    {
        return $this->belongsTo(StorySceneVersion::class, 'story_scene_version_id');
    }

    /** @return BelongsTo<StoryVideoGenerationJob, $this> */
    public function job(): BelongsTo
    {
        return $this->belongsTo(StoryVideoGenerationJob::class, 'story_video_generation_job_id');
    }

    /** @return BelongsTo<StorySceneAudio, $this> */
    public function parentAudio(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_audio_id');
    }

    /** @return BelongsTo<User, $this> */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function roleEnum(): ?StoryAudioRole
    {
        return StoryAudioRole::tryFrom((string) $this->role);
    }

    public function statusEnum(): StoryVideoJobStatus
    {
        return StoryVideoJobStatus::tryFrom((string) $this->status) ?? StoryVideoJobStatus::Queued;
    }

    public function reviewStatusEnum(): StoryReviewStatus
    {
        return StoryReviewStatus::tryFrom((string) $this->review_status) ?? StoryReviewStatus::Draft;
    }

    public function isSelected(): bool
    {
        return $this->selected_at !== null;
    }

    public function hasPrivateFile(): bool
    {
        return is_string($this->disk)
            && $this->disk !== ''
            && is_string($this->path)
            && $this->path !== '';
    }
}
