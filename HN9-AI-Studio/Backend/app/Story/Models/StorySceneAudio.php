<?php

declare(strict_types=1);

namespace App\Story\Models;

use App\Models\Concerns\HasUuid;
use App\Story\Enums\StoryAudioRole;
use App\Story\Enums\StoryVideoJobStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property string $uuid
 * @property int $story_workspace_id
 * @property int $story_scene_id
 * @property int|null $story_scene_version_id
 * @property int|null $story_video_generation_job_id
 * @property string $role
 * @property string $status
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
        'status',
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

    public function roleEnum(): ?StoryAudioRole
    {
        return StoryAudioRole::tryFrom((string) $this->role);
    }

    public function statusEnum(): StoryVideoJobStatus
    {
        return StoryVideoJobStatus::tryFrom((string) $this->status) ?? StoryVideoJobStatus::Queued;
    }

    public function hasPrivateFile(): bool
    {
        return is_string($this->disk)
            && $this->disk !== ''
            && is_string($this->path)
            && $this->path !== '';
    }
}
