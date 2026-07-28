<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\WorkflowRun;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * API representation of a workflow run.
 *
 * @mixin WorkflowRun
 */
class WorkflowResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'workflow_key' => $this->workflow_key,
            'status' => $this->status,
            'current_stage' => $this->current_stage,
            'total_steps' => $this->total_steps,
            'completed_steps' => $this->completed_steps,
            'context' => $this->context,
            'started_at' => $this->started_at?->toIso8601String(),
            'finished_at' => $this->finished_at?->toIso8601String(),
            'duration_ms' => $this->duration_ms,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
