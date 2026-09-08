<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Owner-scoped dashboard overview. Nested project/activity rows reuse the
 * public UUID resources; usage and cost are not included.
 *
 * @property array{
 *     projects: array{total: int, draft: int, active: int, completed: int, archived: int},
 *     scripts: array{total: int, by_status: array<string, int>},
 *     images: array{total: int, by_status: array<string, int>},
 *     videos: array{total: int, by_status: array<string, int>},
 *     assets: array{total: int, by_status: array<string, int>},
 *     recent_projects: \Illuminate\Support\Collection,
 *     recent_activity: \Illuminate\Support\Collection
 * } $resource
 */
class DashboardSummaryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'projects' => $this->resource['projects'],
            'scripts' => $this->resource['scripts'],
            'images' => $this->resource['images'],
            'videos' => $this->resource['videos'],
            'assets' => $this->resource['assets'],
            'recent_projects' => ProjectResource::collection($this->resource['recent_projects'])->resolve(),
            'recent_activity' => DashboardActivityResource::collection($this->resource['recent_activity'])->resolve(),
        ];
    }
}
