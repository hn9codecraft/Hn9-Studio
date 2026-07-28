<?php

declare(strict_types=1);

namespace App\DTOs\Provider;

use App\DTOs\Concerns\ArrayableData;
use App\Enums\Status;

/**
 * Immutable payload describing an AI provider registry entry. Describes which
 * provider exists and its capabilities — it carries no integration code.
 */
final readonly class ProviderData
{
    use ArrayableData;

    /**
     * @param  array<string, mixed>  $capabilities
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public string $slug,
        public string $name,
        public string $category,
        public string $status = Status::Active->value,
        public ?string $base_url = null,
        public int $priority = 0,
        public array $capabilities = [],
        public array $metadata = [],
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            slug: (string) $data['slug'],
            name: (string) $data['name'],
            category: (string) $data['category'],
            status: (string) ($data['status'] ?? Status::Active->value),
            base_url: isset($data['base_url']) ? (string) $data['base_url'] : null,
            priority: (int) ($data['priority'] ?? 0),
            capabilities: (array) ($data['capabilities'] ?? []),
            metadata: (array) ($data['metadata'] ?? []),
        );
    }
}
