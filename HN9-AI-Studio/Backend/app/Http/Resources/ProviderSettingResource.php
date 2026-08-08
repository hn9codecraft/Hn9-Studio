<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\ProviderSetting;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ProviderSetting
 */
class ProviderSettingResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid ?? $this->id,
            'key' => $this->key,
            'value' => $this->is_secret ? $this->maskedValue() : $this->value,
            'is_secret' => $this->is_secret,
            'environment' => $this->environment,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
