<?php

declare(strict_types=1);

namespace App\DTOs\Project;

use App\DTOs\Concerns\ArrayableData;

/**
 * Immutable payload for a partial project update. Every field is nullable;
 * {@see ArrayableData::toArray()} drops nulls so only supplied fields are
 * persisted (PATCH semantics).
 */
final readonly class UpdateProjectData
{
    use ArrayableData;

    /**
     * @param  array<string, mixed>|null  $settings
     * @param  array<string, mixed>|null  $metadata
     */
    public function __construct(
        public ?string $name = null,
        public ?string $slug = null,
        public ?string $description = null,
        public ?string $type = null,
        public ?string $status = null,
        public ?array $settings = null,
        public ?array $metadata = null,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            name: isset($data['name']) ? (string) $data['name'] : null,
            slug: isset($data['slug']) ? (string) $data['slug'] : null,
            description: array_key_exists('description', $data) ? ($data['description'] !== null ? (string) $data['description'] : null) : null,
            type: isset($data['type']) ? (string) $data['type'] : null,
            status: isset($data['status']) ? (string) $data['status'] : null,
            settings: isset($data['settings']) ? (array) $data['settings'] : null,
            metadata: isset($data['metadata']) ? (array) $data['metadata'] : null,
        );
    }
}
