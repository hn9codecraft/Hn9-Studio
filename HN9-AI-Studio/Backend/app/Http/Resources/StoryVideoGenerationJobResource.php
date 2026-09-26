<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Story\Models\StoryVideoGenerationJob;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin StoryVideoGenerationJob
 */
class StoryVideoGenerationJobResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'capability' => $this->capability,
            'provider' => $this->provider_key,
            'model' => $this->model_key,
            'operation_id' => $this->operation_id,
            'status' => $this->status,
            'async_mode' => $this->async_mode,
            'idempotency_key' => $this->idempotency_key,
            'routing' => $this->routing,
            'retry_count' => $this->retry_count,
            'timed_out' => $this->timed_out,
            'error_code' => $this->error_code,
            'error_message' => $this->error_message,
            'submitted_at' => $this->submitted_at?->toIso8601String(),
            'started_at' => $this->started_at?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
            'failed_at' => $this->failed_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
