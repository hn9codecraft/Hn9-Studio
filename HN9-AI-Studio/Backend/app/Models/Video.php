<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasStatus;
use App\Models\Concerns\HasUuid;
use App\Models\Concerns\LogsActivity;
use Database\Factories\VideoFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A studio video request belonging to a project. No generated file until a
 * real provider writes output_url.
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
 * @property string|null $provider
 * @property string|null $provider_job_id
 * @property string|null $output_url
 * @property array<string, mixed>|null $metadata
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
        'provider',
        'provider_job_id',
        'output_url',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'duration' => 'integer',
            'metadata' => 'array',
        ];
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
