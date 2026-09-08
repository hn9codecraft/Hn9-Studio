<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Owner-scoped usage from prompt_executions. Tokens are null when unknown.
 *
 * @property array<string, mixed> $resource
 */
class DashboardUsageResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'operations' => $this->resource['operations'],
            'tokens' => $this->resource['tokens'],
            'by_provider' => $this->resource['by_provider'],
            'by_model' => $this->resource['by_model'],
            'timeline' => $this->resource['timeline'],
        ];
    }
}
