<?php

declare(strict_types=1);

namespace App\Story\Models;

use App\Models\Concerns\HasUuid;
use App\Story\Enums\StoryPlanVersionStatus;
use Database\Factories\StoryPlanVersionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property string $uuid
 * @property int $story_plan_id
 * @property int $version
 * @property string $status
 * @property array|null $plan
 */
class StoryPlanVersion extends Model
{
    /** @use HasFactory<StoryPlanVersionFactory> */
    use HasFactory, HasUuid;

    protected $table = 'story_plan_versions';

    protected $fillable = [
        'story_plan_id',
        'version',
        'status',
        'input',
        'instruction',
        'master_story',
        'plan',
        'remainder_strategy',
        'provider',
        'model',
        'generation',
        'error_message',
    ];

    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'input' => 'array',
            'plan' => 'array',
            'generation' => 'array',
        ];
    }

    protected static function newFactory(): StoryPlanVersionFactory
    {
        return StoryPlanVersionFactory::new();
    }

    /** @return BelongsTo<StoryPlan, $this> */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(StoryPlan::class, 'story_plan_id');
    }

    public function statusEnum(): StoryPlanVersionStatus
    {
        return StoryPlanVersionStatus::tryFrom((string) $this->status)
            ?? StoryPlanVersionStatus::Generating;
    }
}
