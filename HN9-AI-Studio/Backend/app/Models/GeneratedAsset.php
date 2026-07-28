<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasStatus;
use App\Models\Concerns\HasUuid;
use App\Models\Concerns\LogsActivity;
use Database\Factories\GeneratedAssetFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property int $id
 * @property string $uuid
 * @property int $project_id
 * @property string $type
 * @property string $status
 */
class GeneratedAsset extends Model
{
    /** @use HasFactory<GeneratedAssetFactory> */
    use HasFactory, HasStatus, HasUuid, LogsActivity, SoftDeletes;

    protected $fillable = [
        'project_id',
        'generated_content_id',
        'workflow_run_id',
        'agent_execution_id',
        'type',
        'provider',
        'status',
        'prompt',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
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

    /** @return MorphMany<MediaFile, $this> */
    public function mediaFiles(): MorphMany
    {
        return $this->morphMany(MediaFile::class, 'mediable');
    }
}
