<?php

declare(strict_types=1);

namespace App\Story\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property string $uuid
 * @property int $story_workspace_id
 * @property int|null $story_reel_id
 * @property int|null $story_scene_id
 * @property string $kind
 * @property string|null $capability
 * @property string $status
 * @property string|null $error_code
 * @property string|null $error_message
 * @property \Illuminate\Support\Carbon|null $created_at
 */
class StoryGenerationAttempt extends Model
{
    use HasUuid;

    public const STATUS_FAILED = 'failed';

    public const STATUS_NOT_CONNECTED = 'not_connected';

    protected $table = 'story_generation_attempts';

    protected $fillable = [
        'story_workspace_id',
        'story_reel_id',
        'story_scene_id',
        'kind',
        'capability',
        'status',
        'error_code',
        'error_message',
    ];

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
}
