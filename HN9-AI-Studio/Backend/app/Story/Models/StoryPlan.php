<?php

declare(strict_types=1);

namespace App\Story\Models;

use App\Models\Concerns\HasUuid;
use App\Story\Enums\StoryPlanStatus;
use Database\Factories\StoryPlanFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * @property int $id
 * @property string $uuid
 * @property int $story_workspace_id
 * @property string $idea
 * @property int $requested_duration_seconds
 * @property string $status
 * @property int|null $current_version_id
 */
class StoryPlan extends Model
{
    /** @use HasFactory<StoryPlanFactory> */
    use HasFactory, HasUuid;

    protected $table = 'story_plans';

    protected $fillable = [
        'story_workspace_id',
        'title',
        'idea',
        'requested_duration_seconds',
        'duration_unit',
        'status',
        'current_version_id',
    ];

    protected function casts(): array
    {
        return [
            'requested_duration_seconds' => 'integer',
        ];
    }

    protected static function newFactory(): StoryPlanFactory
    {
        return StoryPlanFactory::new();
    }

    /** @return BelongsTo<StoryWorkspace, $this> */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(StoryWorkspace::class, 'story_workspace_id');
    }

    /** @return HasMany<StoryPlanVersion, $this> */
    public function versions(): HasMany
    {
        return $this->hasMany(StoryPlanVersion::class, 'story_plan_id');
    }

    /** @return BelongsTo<StoryPlanVersion, $this> */
    public function currentVersion(): BelongsTo
    {
        return $this->belongsTo(StoryPlanVersion::class, 'current_version_id');
    }

    /** @return HasOne<StoryProductionPlan, $this> */
    public function currentProductionPlan(): HasOne
    {
        return $this->hasOne(StoryProductionPlan::class, 'current_for_story_plan_id');
    }

    public function statusEnum(): StoryPlanStatus
    {
        return StoryPlanStatus::tryFrom((string) $this->status) ?? StoryPlanStatus::Draft;
    }
}
