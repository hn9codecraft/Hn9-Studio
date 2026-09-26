<?php

declare(strict_types=1);

namespace App\Story\Models;

use App\Models\Concerns\HasUuid;
use App\Story\Enums\StorySceneStatus;
use Database\Factories\StorySceneFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property string $uuid
 * @property int $story_reel_id
 * @property int $sequence
 * @property int $duration_seconds
 * @property int $start_second
 * @property int $end_second
 * @property string $status
 * @property array|null $characters
 * @property array|null $dialogue
 * @property array|null $continuity
 * @property int|null $source_plan_version_id
 */
class StoryScene extends Model
{
    /** @use HasFactory<StorySceneFactory> */
    use HasFactory, HasUuid;

    protected $table = 'story_scenes';

    protected $fillable = [
        'story_reel_id',
        'sequence',
        'title',
        'duration_seconds',
        'start_second',
        'end_second',
        'status',
        'story',
        'characters',
        'location',
        'dialogue',
        'narration',
        'visual_prompt',
        'motion_prompt',
        'audio_direction',
        'continuity',
        'source_plan_version_id',
    ];

    protected function casts(): array
    {
        return [
            'sequence' => 'integer',
            'duration_seconds' => 'integer',
            'start_second' => 'integer',
            'end_second' => 'integer',
            'characters' => 'array',
            'dialogue' => 'array',
            'continuity' => 'array',
        ];
    }

    protected static function newFactory(): StorySceneFactory
    {
        return StorySceneFactory::new();
    }

    /** @return BelongsTo<StoryReel, $this> */
    public function reel(): BelongsTo
    {
        return $this->belongsTo(StoryReel::class, 'story_reel_id');
    }

    /** @return BelongsTo<StoryPlanVersion, $this> */
    public function sourcePlanVersion(): BelongsTo
    {
        return $this->belongsTo(StoryPlanVersion::class, 'source_plan_version_id');
    }

    public function statusEnum(): StorySceneStatus
    {
        return StorySceneStatus::tryFrom((string) $this->status) ?? StorySceneStatus::Draft;
    }

    public function allowsEdit(): bool
    {
        return $this->statusEnum()->allowsEdit();
    }
}
