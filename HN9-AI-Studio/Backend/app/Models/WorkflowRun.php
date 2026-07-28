<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasCreator;
use App\Models\Concerns\HasStatus;
use App\Models\Concerns\HasUuid;
use App\Models\Concerns\LogsActivity;
use App\Models\Concerns\TracksExecution;
use Database\Factories\WorkflowRunFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $uuid
 * @property int $project_id
 * @property string $workflow_key
 * @property string $status
 * @property string|null $current_stage
 * @property int|null $total_steps
 * @property int|null $completed_steps
 * @property int|null $duration_ms
 * @property Carbon|null $started_at
 * @property Carbon|null $finished_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class WorkflowRun extends Model
{
    /** @use HasFactory<WorkflowRunFactory> */
    use HasCreator, HasFactory, HasStatus, HasUuid, LogsActivity, SoftDeletes, TracksExecution;

    protected $fillable = [
        'project_id',
        'user_id',
        'workflow_key',
        'status',
        'current_stage',
        'total_steps',
        'completed_steps',
        'context',
        'error',
        'started_at',
        'finished_at',
        'duration_ms',
    ];

    protected function casts(): array
    {
        return [
            'context' => 'array',
            'total_steps' => 'integer',
            'completed_steps' => 'integer',
            'duration_ms' => 'integer',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<AgentExecution, $this> */
    public function agentExecutions(): HasMany
    {
        return $this->hasMany(AgentExecution::class);
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
}
