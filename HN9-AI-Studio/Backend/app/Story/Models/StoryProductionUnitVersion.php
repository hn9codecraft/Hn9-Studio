<?php

declare(strict_types=1);

namespace App\Story\Models;

use App\Models\Concerns\HasUuid;
use App\Models\User;
use App\Story\Enums\StoryReviewStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One successful candidate for one Generation Unit. The generation job stays
 * the attempt. This row is created only after that attempt's output is accepted.
 *
 * @property int $id
 * @property string $uuid
 * @property int $story_production_unit_id
 * @property int|null $story_video_generation_job_id
 * @property int $version_number
 * @property string $review_status
 * @property string|null $review_comment
 * @property int|null $reviewed_by
 * @property string|null $provider_key
 * @property string|null $model_key
 * @property string|null $capability
 * @property int $requested_duration_seconds
 * @property float|string $produced_duration_seconds
 * @property string $disk
 * @property string $path
 * @property string|null $mime
 * @property int|null $size
 * @property array<string, mixed>|null $snapshot
 */
class StoryProductionUnitVersion extends Model
{
    use HasUuid;

    protected $table = 'story_production_unit_versions';

    /** Written by StoryProductionUnitVersionService. Request bodies are never assigned here. */
    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'version_number' => 'integer',
            'requested_duration_seconds' => 'integer',
            'produced_duration_seconds' => 'float',
            'size' => 'integer',
            'snapshot' => 'array',
            'reviewed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<StoryProductionUnit, $this> */
    public function unit(): BelongsTo
    {
        return $this->belongsTo(StoryProductionUnit::class, 'story_production_unit_id');
    }

    /** @return BelongsTo<StoryVideoGenerationJob, $this> */
    public function job(): BelongsTo
    {
        return $this->belongsTo(StoryVideoGenerationJob::class, 'story_video_generation_job_id');
    }

    /** @return BelongsTo<User, $this> */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function reviewStatusEnum(): StoryReviewStatus
    {
        return StoryReviewStatus::tryFrom((string) $this->review_status) ?? StoryReviewStatus::PendingReview;
    }

    public function isApproved(): bool
    {
        return $this->reviewStatusEnum() === StoryReviewStatus::Approved;
    }
}
