<?php

declare(strict_types=1);

namespace App\DTOs\Provider;

use App\DTOs\Concerns\ArrayableData;

/**
 * Immutable payload for a single provider configuration entry. Secret values
 * are flagged via {@see self::$is_secret}; encryption at rest is handled by
 * the model layer.
 */
final readonly class ProviderSettingData
{
    use ArrayableData;

    public function __construct(
        public int $ai_provider_id,
        public string $key,
        public ?string $value = null,
        public bool $is_secret = false,
        public string $environment = 'production',
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            ai_provider_id: (int) $data['ai_provider_id'],
            key: (string) $data['key'],
            value: array_key_exists('value', $data) && $data['value'] !== null ? (string) $data['value'] : null,
            is_secret: (bool) ($data['is_secret'] ?? false),
            environment: (string) ($data['environment'] ?? 'production'),
        );
    }
}
