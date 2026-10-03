<?php

declare(strict_types=1);

namespace App\Story\Models;

use App\Models\Concerns\HasUuid;
use App\Models\User;
use App\Story\Enums\StoryPlanReviewStatus;
use App\Story\Enums\StoryPlanVersionStatus;
use Database\Factories\StoryPlanVersionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $uuid
 * @property int $story_plan_id
 * @property int $version
 * @property string $status
 * @property array|null $plan
 * @property Carbon|null $approved_at
 * @property int|null $approved_by
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
            'approved_at' => 'datetime',
            'approved_by' => 'integer',
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

    /** @return BelongsTo<User, $this> */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function statusEnum(): StoryPlanVersionStatus
    {
        return StoryPlanVersionStatus::tryFrom((string) $this->status)
            ?? StoryPlanVersionStatus::Generating;
    }

    public function isApproved(): bool
    {
        return $this->approved_at !== null;
    }

    public function reviewStatus(): StoryPlanReviewStatus
    {
        return match (true) {
            $this->isApproved() => StoryPlanReviewStatus::Approved,
            $this->statusEnum() === StoryPlanVersionStatus::Completed => StoryPlanReviewStatus::ReadyForReview,
            $this->statusEnum() === StoryPlanVersionStatus::Failed => StoryPlanReviewStatus::Failed,
            default => StoryPlanReviewStatus::InProgress,
        };
    }
}
