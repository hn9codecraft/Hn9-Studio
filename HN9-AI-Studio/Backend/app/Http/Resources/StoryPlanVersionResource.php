<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Story\Models\StoryPlanVersion;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin StoryPlanVersion
 */
class StoryPlanVersionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'version' => $this->version,
            'status' => $this->status,
            'instruction' => $this->instruction,
            'master_story' => $this->master_story,
            'plan' => $this->safePlan(),
            'remainder_strategy' => $this->remainder_strategy,
            'provider' => $this->provider,
            'model' => $this->model,
            'generation' => $this->safeGeneration(),
            'error_message' => $this->error_message,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function safePlan(): ?array
    {
        return is_array($this->plan) ? $this->plan : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function safeGeneration(): ?array
    {
        $generation = $this->generation;
        if (! is_array($generation)) {
            return null;
        }

        $blocked = ['api_key', 'authorization', 'token', 'secret', 'password', 'path', 'disk'];
        $clean = [];
        foreach ($generation as $key => $value) {
            $lower = strtolower((string) $key);
            foreach ($blocked as $needle) {
                if (str_contains($lower, $needle)) {
                    continue 2;
                }
            }
            if (is_scalar($value) || $value === null || is_array($value)) {
                $clean[$key] = $value;
            }
        }

        return $clean;
    }
}
