<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasCreator;
use App\Models\Concerns\HasStatus;
use App\Models\Concerns\HasUuid;
use App\Models\Concerns\LogsActivity;
use Database\Factories\ProjectFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property int $id
 * @property string $uuid
 * @property int $user_id
 * @property string $name
 * @property string $slug
 * @property string $status
 * @property array<string, mixed>|null $settings
 * @property array<string, mixed>|null $metadata
 */
class Project extends Model
{
    /** @use HasFactory<ProjectFactory> */
    use HasCreator, HasFactory, HasStatus, HasUuid, LogsActivity, SoftDeletes;

    protected $fillable = [
        'user_id',
        'name',
        'slug',
        'description',
        'type',
        'status',
        'settings',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'settings' => 'array',
            'metadata' => 'array',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<ProjectInput, $this> */
    public function inputs(): HasMany
    {
        return $this->hasMany(ProjectInput::class);
    }

    /** @return HasMany<WorkflowRun, $this> */
    public function workflowRuns(): HasMany
    {
        return $this->hasMany(WorkflowRun::class);
    }

    /** @return HasMany<GeneratedContent, $this> */
    public function generatedContents(): HasMany
    {
        return $this->hasMany(GeneratedContent::class);
    }

    /** @return HasMany<GeneratedAsset, $this> */
    public function generatedAssets(): HasMany
    {
        return $this->hasMany(GeneratedAsset::class);
    }

    /** @return HasMany<PublishJob, $this> */
    public function publishJobs(): HasMany
    {
        return $this->hasMany(PublishJob::class);
    }

    /** @return MorphMany<MediaFile, $this> */
    public function mediaFiles(): MorphMany
    {
        return $this->morphMany(MediaFile::class, 'mediable');
    }
}
