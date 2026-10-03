<?php

declare(strict_types=1);

namespace App\Story\Models;

use App\Models\Concerns\HasUuid;
use App\Models\User;
use App\Story\Enums\StoryVideoJobStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One attempt to join a scene's selected unit videos into one scene file.
 *
 * @property int $id
 * @property string $uuid
 * @property int $story_workspace_id
 * @property int $story_production_plan_scene_id
 * @property int|null $requested_by
 * @property int|null $version_number
 * @property string $idempotency_key
 * @property string $status
 * @property array<string, mixed>|null $snapshot
 * @property int $expected_duration_seconds
 * @property string|null $disk
 * @property string|null $path
 * @property string|null $mime
 * @property int|null $size_bytes
 * @property float|string|null $duration_seconds
 * @property string|null $error_code
 * @property string|null $error_message
 */
class StorySceneAssembly extends Model
{
    use HasUuid;

    protected $table = 'story_scene_assemblies';

    /** Written by StorySceneAssemblyService. Request bodies are never assigned here. */
    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'version_number' => 'integer',
            'snapshot' => 'array',
            'expected_duration_seconds' => 'integer',
            'size_bytes' => 'integer',
            'duration_seconds' => 'float',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'failed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<StoryProductionPlanScene, $this> */
    public function planScene(): BelongsTo
    {
        return $this->belongsTo(StoryProductionPlanScene::class, 'story_production_plan_scene_id');
    }

    /** @return BelongsTo<User, $this> */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function statusEnum(): StoryVideoJobStatus
    {
        return StoryVideoJobStatus::tryFrom((string) $this->status) ?? StoryVideoJobStatus::Failed;
    }

    public function isComplete(): bool
    {
        return $this->statusEnum() === StoryVideoJobStatus::Completed;
    }
}
