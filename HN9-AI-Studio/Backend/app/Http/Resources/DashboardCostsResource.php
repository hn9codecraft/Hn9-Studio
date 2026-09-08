<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Owner-scoped recorded costs from prompt_executions.cost.
 * has_records is false when no billed rows exist.
 *
 * @property array<string, mixed> $resource
 */
class DashboardCostsResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'has_records' => $this->resource['has_records'],
            'message' => $this->resource['message'],
            'totals' => $this->resource['totals'],
            'by_provider' => $this->resource['by_provider'],
            'by_model' => $this->resource['by_model'],
            'timeline' => $this->resource['timeline'],
        ];
    }
}
