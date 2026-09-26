<?php

declare(strict_types=1);

namespace App\Story\Models;

use App\Models\Concerns\HasUuid;
use App\Story\Enums\StoryVideoAsyncMode;
use App\Story\Enums\StoryVideoCapability;
use App\Story\Enums\StoryVideoJobStatus;
use Database\Factories\StoryVideoGenerationJobFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property string $uuid
 * @property int $story_workspace_id
 * @property string $capability
 * @property string $status
 * @property string|null $idempotency_key
 */
class StoryVideoGenerationJob extends Model
{
    /** @use HasFactory<StoryVideoGenerationJobFactory> */
    use HasFactory, HasUuid;

    protected $table = 'story_video_generation_jobs';

    protected $fillable = [
        'story_workspace_id',
        'story_reel_id',
        'story_scene_id',
        'capability',
        'provider_key',
        'model_key',
        'operation_id',
        'status',
        'async_mode',
        'idempotency_key',
        'request_payload',
        'routing',
        'provider_metadata',
        'error_code',
        'error_message',
        'retry_count',
        'timed_out',
        'submitted_at',
        'started_at',
        'completed_at',
        'failed_at',
    ];

    protected function casts(): array
    {
        return [
            'request_payload' => 'array',
            'routing' => 'array',
            'provider_metadata' => 'array',
            'retry_count' => 'integer',
            'timed_out' => 'boolean',
            'submitted_at' => 'datetime',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'failed_at' => 'datetime',
        ];
    }

    protected static function newFactory(): StoryVideoGenerationJobFactory
    {
        return StoryVideoGenerationJobFactory::new();
    }

    /** @return BelongsTo<StoryWorkspace, $this> */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(StoryWorkspace::class, 'story_workspace_id');
    }

    /** @return BelongsTo<StoryReel, $this> */
    public function reel(): BelongsTo
    {
        return $this->belongsTo(StoryReel::class, 'story_reel_id');
    }

    /** @return BelongsTo<StoryScene, $this> */
    public function scene(): BelongsTo
    {
        return $this->belongsTo(StoryScene::class, 'story_scene_id');
    }

    public function statusEnum(): StoryVideoJobStatus
    {
        return StoryVideoJobStatus::tryFrom((string) $this->status) ?? StoryVideoJobStatus::Queued;
    }

    public function capabilityEnum(): ?StoryVideoCapability
    {
        return StoryVideoCapability::tryFrom((string) $this->capability);
    }

    public function asyncModeEnum(): ?StoryVideoAsyncMode
    {
        return $this->async_mode === null
            ? null
            : StoryVideoAsyncMode::tryFrom((string) $this->async_mode);
    }
}
