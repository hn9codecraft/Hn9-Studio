<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasStatus;
use App\Models\Concerns\HasUuid;
use App\Models\Concerns\LogsActivity;
use Database\Factories\GeneratedContentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property int $id
 * @property string $uuid
 * @property int $project_id
 * @property string $type
 * @property string $status
 */
class GeneratedContent extends Model
{
    /** @use HasFactory<GeneratedContentFactory> */
    use HasFactory, HasStatus, HasUuid, LogsActivity, SoftDeletes;

    protected $fillable = [
        'project_id',
        'workflow_run_id',
        'agent_execution_id',
        'type',
        'channel',
        'language',
        'title',
        'body',
        'structured',
        'status',
        'version',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'structured' => 'array',
            'metadata' => 'array',
            'version' => 'integer',
        ];
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** @return BelongsTo<WorkflowRun, $this> */
    public function workflowRun(): BelongsTo
    {
        return $this->belongsTo(WorkflowRun::class);
    }

    /** @return BelongsTo<AgentExecution, $this> */
    public function agentExecution(): BelongsTo
    {
        return $this->belongsTo(AgentExecution::class);
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
