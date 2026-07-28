<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasStatus;
use App\Models\Concerns\HasUuid;
use App\Models\Concerns\TracksExecution;
use Database\Factories\AgentExecutionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property int $id
 * @property string $uuid
 * @property int $workflow_run_id
 * @property string $agent_key
 * @property string $status
 */
class AgentExecution extends Model
{
    /** @use HasFactory<AgentExecutionFactory> */
    use HasFactory, HasStatus, HasUuid, SoftDeletes, TracksExecution;

    protected $fillable = [
        'workflow_run_id',
        'ai_provider_id',
        'agent_key',
        'agent_version',
        'status',
        'attempt',
        'input',
        'output',
        'error',
        'tokens_used',
        'cost',
        'started_at',
        'finished_at',
        'duration_ms',
    ];

    protected function casts(): array
    {
        return [
            'input' => 'array',
            'output' => 'array',
            'attempt' => 'integer',
            'tokens_used' => 'integer',
            'cost' => 'decimal:6',
            'duration_ms' => 'integer',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<WorkflowRun, $this> */
    public function workflowRun(): BelongsTo
    {
        return $this->belongsTo(WorkflowRun::class);
    }

    /** @return BelongsTo<AiProvider, $this> */
    public function aiProvider(): BelongsTo
    {
        return $this->belongsTo(AiProvider::class);
    }

    /** @return HasMany<PromptExecution, $this> */
    public function promptExecutions(): HasMany
    {
        return $this->hasMany(PromptExecution::class);
    }
}
