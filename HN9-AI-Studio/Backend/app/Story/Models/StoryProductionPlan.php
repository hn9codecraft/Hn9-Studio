<?php

declare(strict_types=1);

namespace App\Story\Models;

use App\Models\Concerns\HasUuid;
use App\Models\User;
use App\Story\Enums\StoryProductionPlanStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * How one Story Plan Version's scenes are split into generation units. Never edited
 * after creation: a change produces a new revision that points back at this one.
 *
 * @property int $id
 * @property string $uuid
 * @property int $story_workspace_id
 * @property int $story_plan_id
 * @property int $story_plan_version_id
 * @property int $story_reel_id
 * @property int|null $previous_plan_id
 * @property int $revision
 * @property string $status
 * @property int|null $current_for_story_plan_id
 * @property int $unit_seconds
 * @property int $total_duration_seconds
 * @property int|null $created_by
 * @property Carbon|null $superseded_at
 */
class StoryProductionPlan extends Model
{
    use HasUuid;

    protected $table = 'story_production_plans';

    /** Written only by StoryProductionPlanService; nothing here is request data. */
    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'revision' => 'integer',
            'unit_seconds' => 'integer',
            'total_duration_seconds' => 'integer',
            'superseded_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<StoryWorkspace, $this> */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(StoryWorkspace::class, 'story_workspace_id');
    }

    /** @return BelongsTo<StoryPlan, $this> */
    public function storyPlan(): BelongsTo
    {
        return $this->belongsTo(StoryPlan::class, 'story_plan_id');
    }

    /** @return BelongsTo<StoryPlanVersion, $this> */
    public function sourceVersion(): BelongsTo
    {
        return $this->belongsTo(StoryPlanVersion::class, 'story_plan_version_id');
    }

    /** @return BelongsTo<StoryReel, $this> */
    public function reel(): BelongsTo
    {
        return $this->belongsTo(StoryReel::class, 'story_reel_id');
    }

    /** @return BelongsTo<StoryProductionPlan, $this> */
    public function previousPlan(): BelongsTo
    {
        return $this->belongsTo(self::class, 'previous_plan_id');
    }

    /** @return HasOne<StoryProductionPlan, $this> */
    public function nextPlan(): HasOne
    {
        return $this->hasOne(self::class, 'previous_plan_id');
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return HasMany<StoryProductionPlanScene, $this> */
    public function scenes(): HasMany
    {
        return $this->hasMany(StoryProductionPlanScene::class, 'story_production_plan_id');
    }

    /** @return HasManyThrough<StoryProductionUnit, StoryProductionPlanScene, $this> */
    public function units(): HasManyThrough
    {
        return $this->hasManyThrough(
            StoryProductionUnit::class,
            StoryProductionPlanScene::class,
            'story_production_plan_id',
            'story_production_plan_scene_id',
        );
    }

    public function statusEnum(): StoryProductionPlanStatus
    {
        return StoryProductionPlanStatus::tryFrom((string) $this->status) ?? StoryProductionPlanStatus::Superseded;
    }

    public function isCurrent(): bool
    {
        return $this->statusEnum() === StoryProductionPlanStatus::Active;
    }
}
