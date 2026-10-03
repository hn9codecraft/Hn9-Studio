<?php

declare(strict_types=1);

namespace App\Story\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One timeline slot inside a planned scene. The row is the slot's lasting identity:
 * generated media for the slot is attached to it, the slot itself is never replaced.
 *
 * @property int $id
 * @property string $uuid
 * @property int $story_production_plan_scene_id
 * @property int $sequence
 * @property int $start_second
 * @property int $duration_seconds
 * @property int|null $selected_version_id
 */
class StoryProductionUnit extends Model
{
    use HasUuid;

    protected $table = 'story_production_units';

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

    /** @return BelongsTo<StoryProductionPlanScene, $this> */
    public function planScene(): BelongsTo
    {
        return $this->belongsTo(StoryProductionPlanScene::class, 'story_production_plan_scene_id');
    }

    /** @return HasMany<StoryProductionUnitVersion, $this> */
    public function versions(): HasMany
    {
        return $this->hasMany(StoryProductionUnitVersion::class, 'story_production_unit_id');
    }

    /** @return BelongsTo<StoryProductionUnitVersion, $this> */
    public function selectedVersion(): BelongsTo
    {
        return $this->belongsTo(StoryProductionUnitVersion::class, 'selected_version_id');
    }
}
