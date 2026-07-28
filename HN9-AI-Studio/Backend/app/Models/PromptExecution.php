<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasStatus;
use App\Models\Concerns\HasUuid;
use Database\Factories\PromptExecutionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property int $id
 * @property string $uuid
 * @property int $agent_execution_id
 * @property string $template_key
 * @property string $status
 */
class PromptExecution extends Model
{
    /** @use HasFactory<PromptExecutionFactory> */
    use HasFactory, HasStatus, HasUuid, SoftDeletes;

    protected $fillable = [
        'agent_execution_id',
        'ai_provider_id',
        'template_key',
        'template_version',
        'model',
        'status',
        'rendered_prompt',
        'variables',
        'response',
        'prompt_tokens',
        'completion_tokens',
        'total_tokens',
        'cost',
        'latency_ms',
        'error',
    ];

    protected function casts(): array
    {
        return [
            'variables' => 'array',
            'prompt_tokens' => 'integer',
            'completion_tokens' => 'integer',
            'total_tokens' => 'integer',
            'cost' => 'decimal:6',
            'latency_ms' => 'integer',
        ];
    }

    /** @return BelongsTo<AgentExecution, $this> */
    public function agentExecution(): BelongsTo
    {
        return $this->belongsTo(AgentExecution::class);
    }

    /** @return BelongsTo<AiProvider, $this> */
    public function aiProvider(): BelongsTo
    {
        return $this->belongsTo(AiProvider::class);
    }
}
