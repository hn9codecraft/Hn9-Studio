<?php

declare(strict_types=1);

namespace App\Story\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A scene's place in a production plan: its order, its offset in the plan and the
 * length it had when the plan was made. Scene content stays on the story scene.
 *
 * @property int $id
 * @property string $uuid
 * @property int $story_production_plan_id
 * @property int $story_scene_id
 * @property int|null $story_scene_version_id
 * @property int $sequence
 * @property int $start_second
 * @property int $duration_seconds
 */
class StoryProductionPlanScene extends Model
{
    use HasUuid;

    protected $table = 'story_production_plan_scenes';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'sequence' => 'integer',
            'start_second' => 'integer',
            'duration_seconds' => 'integer',
        ];
    }

    public function endSecond(): int
    {
        return $this->start_second + $this->duration_seconds;
    }

    /** @return BelongsTo<StoryProductionPlan, $this> */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(StoryProductionPlan::class, 'story_production_plan_id');
    }

    /** @return BelongsTo<StoryScene, $this> */
    public function scene(): BelongsTo
    {
        return $this->belongsTo(StoryScene::class, 'story_scene_id');
    }

    /** @return BelongsTo<StorySceneVersion, $this> */
    public function sceneVersion(): BelongsTo
    {
        return $this->belongsTo(StorySceneVersion::class, 'story_scene_version_id');
    }

    /** @return HasMany<StoryProductionUnit, $this> */
    public function units(): HasMany
    {
        return $this->hasMany(StoryProductionUnit::class, 'story_production_plan_scene_id');
    }
}
