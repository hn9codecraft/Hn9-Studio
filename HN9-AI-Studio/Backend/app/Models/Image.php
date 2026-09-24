<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ImageReviewAction;
use App\Enums\ImageStatus;
use App\Models\Concerns\HasStatus;
use App\Models\Concerns\HasUuid;
use App\Models\Concerns\LogsActivity;
use Database\Factories\ImageFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A studio image belonging to a project. AI rows point at a stored file and
 * a generated asset. Regeneration creates a new row; it does not replace this one.
 *
 * @property int $id
 * @property string $uuid
 * @property int $project_id
 * @property string $title
 * @property string $prompt
 * @property string|null $negative_prompt
 * @property string $aspect_ratio
 * @property string $status
 * @property string $source
 * @property int|null $script_id
 * @property int|null $parent_image_id
 * @property int|null $generated_content_id
 * @property int|null $generated_asset_id
 * @property string|null $provider
 * @property string|null $provider_job_id
 * @property string|null $output_url
 * @property array<string, mixed>|null $metadata
 * @property array<string, mixed>|null $generation
 */
class Image extends Model
{
    /** @use HasFactory<ImageFactory> */
    use HasFactory, HasStatus, HasUuid, LogsActivity, SoftDeletes;

    protected $fillable = [
        'project_id',
        'title',
        'prompt',
        'negative_prompt',
        'aspect_ratio',
        'status',
        'source',
        'script_id',
        'parent_image_id',
        'generated_content_id',
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
    public function parentImage(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_image_id');
    }

    /** @return HasMany<Image, $this> */
    public function variations(): HasMany
    {
        return $this->hasMany(self::class, 'parent_image_id');
    }

    /** @return BelongsTo<GeneratedContent, $this> */
    public function generatedContent(): BelongsTo
    {
        return $this->belongsTo(GeneratedContent::class);
    }

    /** @return BelongsTo<GeneratedAsset, $this> */
    public function generatedAsset(): BelongsTo
    {
        return $this->belongsTo(GeneratedAsset::class);
    }

    /** @return MorphOne<MediaFile, $this> */
    public function file(): MorphOne
    {
        return $this->morphOne(MediaFile::class, 'mediable')->latestOfMany();
    }

    /** @return HasMany<ImageReviewEvent, $this> */
    public function reviewEvents(): HasMany
    {
        return $this->hasMany(ImageReviewEvent::class);
    }

    /** @return HasOne<ImageReviewEvent, $this> */
    public function latestReviewEvent(): HasOne
    {
        return $this->hasOne(ImageReviewEvent::class)->latestOfMany();
    }

    /** @return HasOne<ImageReviewEvent, $this> */
    public function latestReworkEvent(): HasOne
    {
        return $this->hasOne(ImageReviewEvent::class)->ofMany(
            ['id' => 'max'],
            fn ($query) => $query->where('action', ImageReviewAction::NeedsRework->value),
        );
    }

    public function statusEnum(): ImageStatus
    {
        return ImageStatus::tryFrom((string) $this->status) ?? ImageStatus::Draft;
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
