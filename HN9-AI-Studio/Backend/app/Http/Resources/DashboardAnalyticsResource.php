<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Owner-scoped studio analytics. Counts and timelines are real table
 * aggregations; usage and cost are omitted.
 *
 * @property array<string, mixed> $resource
 */
class DashboardAnalyticsResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'projects' => $this->resource['projects'],
            'content' => $this->resource['content'],
            'creation_timeline' => $this->resource['creation_timeline'],
            'activity' => $this->resource['activity'],
            'project_productivity' => $this->resource['project_productivity'],
        ];
    }
}
