<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Export;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Public export package. Storage path, disk, and fingerprint stay off the wire.
 *
 * @mixin Export
 */
class ExportResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'project_id' => $this->project?->uuid,
            'status' => $this->status,
            'filename' => $this->filename,
            'size' => $this->size,
            'error' => $this->error,
            'manifest' => $this->publicManifest(),
            'completed_at' => $this->completed_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function publicManifest(): ?array
    {
        if (! is_array($this->manifest)) {
            return null;
        }

        return $this->manifest;
    }
}
