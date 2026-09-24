<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\ScriptReviewEvent;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ScriptReviewEvent
 */
class ScriptReviewEventResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'action' => $this->action,
            'comment' => $this->comment,
            'from_status' => $this->from_status,
            'to_status' => $this->to_status,
            'actor' => $this->user === null ? null : [
                'id' => $this->user->uuid,
                'name' => $this->user->name,
            ],
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
