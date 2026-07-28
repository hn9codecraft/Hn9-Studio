<?php

declare(strict_types=1);

namespace App\DTOs\Project;

use App\DTOs\Concerns\ArrayableData;
use App\Enums\ProjectStatus;
use App\Http\Requests\StoreProjectRequest;

/**
 * Immutable payload for creating a project. Built from already-validated
 * request input by {@see StoreProjectRequest}.
 */
final readonly class CreateProjectData
{
    use ArrayableData;

    /**
     * @param  array<string, mixed>  $settings
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public int $user_id,
        public string $name,
        public ?string $slug = null,
        public ?string $description = null,
        public ?string $type = null,
        public string $status = ProjectStatus::Draft->value,
        public array $settings = [],
        public array $metadata = [],
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            user_id: (int) $data['user_id'],
            name: (string) $data['name'],
            slug: isset($data['slug']) ? (string) $data['slug'] : null,
            description: isset($data['description']) ? (string) $data['description'] : null,
            type: isset($data['type']) ? (string) $data['type'] : null,
            status: (string) ($data['status'] ?? ProjectStatus::Draft->value),
            settings: (array) ($data['settings'] ?? []),
            metadata: (array) ($data['metadata'] ?? []),
        );
    }
}
