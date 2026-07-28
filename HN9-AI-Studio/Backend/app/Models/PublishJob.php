<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasCreator;
use App\Models\Concerns\HasStatus;
use App\Models\Concerns\HasUuid;
use Database\Factories\PublishJobFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property int $id
 * @property string $uuid
 * @property int $project_id
 * @property string $channel
 * @property string $status
 */
class PublishJob extends Model
{
    /** @use HasFactory<PublishJobFactory> */
    use HasCreator, HasFactory, HasStatus, HasUuid, SoftDeletes;

    protected $fillable = [
        'project_id',
        'generated_content_id',
        'generated_asset_id',
        'user_id',
        'channel',
        'status',
        'scheduled_at',
        'published_at',
        'external_id',
        'external_url',
        'payload',
        'response',
        'error',
        'attempts',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'response' => 'array',
            'attempts' => 'integer',
            'scheduled_at' => 'datetime',
            'published_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
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

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
