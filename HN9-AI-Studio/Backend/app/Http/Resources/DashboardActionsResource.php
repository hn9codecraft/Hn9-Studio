<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Owner-scoped Action Center payload. Identifiers are public UUIDs only.
 *
 * @property array<string, mixed> $resource
 */
class DashboardActionsResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'total' => $this->resource['total'],
            'limit' => $this->resource['limit'],
            'items' => $this->resource['items'],
        ];
    }
}
