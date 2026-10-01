<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Story\Models\StoryExport;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Story final package. Storage disk and path stay off the wire.
 *
 * @mixin StoryExport
 */
class StoryExportResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'project_id' => $this->project?->uuid,
            'render_id' => $this->render?->uuid,
            'status' => $this->status,
            'filename' => $this->filename,
            'size' => $this->size,
            'error' => $this->error,
            'manifest' => is_array($this->manifest) ? $this->manifest : null,
            'output_url' => null,
            'completed_at' => $this->completed_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
