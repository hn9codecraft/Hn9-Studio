<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\VideoReviewAction;
use App\Enums\VideoStatus;
use App\Models\Concerns\HasStatus;
use App\Models\Concerns\HasUuid;
use App\Models\Concerns\LogsActivity;
use Database\Factories\VideoFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A studio video belonging to a project. AI output is stored as a MediaFile;
 * output_url is never a temporary provider URI.
 *
 * @property int $id
 * @property string $uuid
 * @property int $project_id
 * @property string $title
 * @property string $prompt
 * @property string|null $negative_prompt
 * @property string $aspect_ratio
 * @property int $duration
 * @property string $status
 * @property string $source
 * @property int|null $script_id
 * @property int|null $image_id
 * @property int|null $parent_video_id
 * @property int|null $generated_asset_id
 * @property string|null $provider
 * @property string|null $provider_job_id
 * @property string|null $output_url
 * @property array<string, mixed>|null $metadata
 * @property array<string, mixed>|null $generation
 */
class Video extends Model
{
    /** @use HasFactory<VideoFactory> */
    use HasFactory, HasStatus, HasUuid, LogsActivity, SoftDeletes;

    protected $fillable = [
        'project_id',
        'title',
        'prompt',
        'negative_prompt',
        'aspect_ratio',
        'duration',
        'status',
        'source',
        'script_id',
        'image_id',
        'parent_video_id',
        'generated_asset_id',
        'provider',
        'provider_job_id',
        'output_url',
        'metadata',
        'generation',
    ];

    protected function casts(): array
    {
        return [
            'duration' => 'integer',
            'metadata' => 'array',
            'generation' => 'array',
        ];
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** @return BelongsTo<Script, $this> */
    public function script(): BelongsTo
    {
        return $this->belongsTo(Script::class);
    }

    /** @return BelongsTo<Image, $this> */
    public function image(): BelongsTo
    {
        return $this->belongsTo(Image::class);
    }

    /** @return BelongsTo<Video, $this> */
    public function parentVideo(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_video_id');
    }

    /** @return HasMany<Video, $this> */
    public function variations(): HasMany
    {
        return $this->hasMany(self::class, 'parent_video_id');
    }

    /** @return BelongsTo<GeneratedAsset, $this> */
    public function generatedAsset(): BelongsTo
    {
        return $this->belongsTo(GeneratedAsset::class);
    }

    /** @return MorphOne<MediaFile, $this> */
    public function file(): MorphOne
    {
        return $this->morphOne(MediaFile::class, 'mediable');
    }

    /** @return HasMany<VideoReviewEvent, $this> */
    public function reviewEvents(): HasMany
    {
        return $this->hasMany(VideoReviewEvent::class);
    }

    /** @return HasOne<VideoReviewEvent, $this> */
    public function latestReviewEvent(): HasOne
    {
        return $this->hasOne(VideoReviewEvent::class)->latestOfMany();
    }

    /** @return HasOne<VideoReviewEvent, $this> */
    public function latestReworkEvent(): HasOne
    {
        return $this->hasOne(VideoReviewEvent::class)->ofMany(
            ['id' => 'max'],
            fn ($query) => $query->where('action', VideoReviewAction::NeedsRework->value),
        );
    }

    public function statusEnum(): VideoStatus
    {
        return VideoStatus::tryFrom((string) $this->status) ?? VideoStatus::Draft;
    }

    public function allowsContentEdit(): bool
    {
        return $this->statusEnum()->allowsContentEdit();
    }

    public function isSubmittable(): bool
    {
        return $this->statusEnum()->isSubmittable();
    }

    public function isReviewable(): bool
    {
        return $this->statusEnum()->isReviewable();
    }

    public function allowsRegeneration(): bool
    {
        return $this->statusEnum()->allowsRegeneration();
    }
}
