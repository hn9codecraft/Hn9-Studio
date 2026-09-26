<?php

declare(strict_types=1);

namespace App\Story\Models;

use App\Models\Concerns\HasUuid;
use App\Story\Enums\StoryReelStatus;
use Database\Factories\StoryReelFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $uuid
 * @property int $story_workspace_id
 * @property string $title
 * @property int $sequence
 * @property string $status
 * @property int $total_duration_seconds
 * @property int|null $source_plan_id
 * @property int|null $source_plan_version_id
 */
class StoryReel extends Model
{
    /** @use HasFactory<StoryReelFactory> */
    use HasFactory, HasUuid;

    protected $table = 'story_reels';

    protected $fillable = [
        'story_workspace_id',
        'title',
        'description',
        'sequence',
        'status',
        'total_duration_seconds',
        'source_plan_id',
        'source_plan_version_id',
    ];

    protected function casts(): array
    {
        return [
            'sequence' => 'integer',
            'total_duration_seconds' => 'integer',
        ];
    }

    protected static function newFactory(): StoryReelFactory
    {
        return StoryReelFactory::new();
    }

    /** @return BelongsTo<StoryWorkspace, $this> */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(StoryWorkspace::class, 'story_workspace_id');
    }

    /** @return HasMany<StoryScene, $this> */
    public function scenes(): HasMany
    {
        return $this->hasMany(StoryScene::class, 'story_reel_id');
    }

    /** @return BelongsTo<StoryPlan, $this> */
    public function sourcePlan(): BelongsTo
    {
        return $this->belongsTo(StoryPlan::class, 'source_plan_id');
    }

    /** @return BelongsTo<StoryPlanVersion, $this> */
    public function sourcePlanVersion(): BelongsTo
    {
        return $this->belongsTo(StoryPlanVersion::class, 'source_plan_version_id');
    }

    public function statusEnum(): StoryReelStatus
    {
        return StoryReelStatus::tryFrom((string) $this->status) ?? StoryReelStatus::Draft;
    }

    public function allowsEdit(): bool
    {
        return $this->statusEnum()->allowsEdit();
    }
}
