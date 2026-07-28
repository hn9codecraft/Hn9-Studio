<?php

declare(strict_types=1);

namespace App\DTOs\Generation;

use App\DTOs\Concerns\ArrayableData;
use App\Support\DomainHelper;

/**
 * Immutable description of a request to generate a deliverable for a project.
 *
 * This DTO captures *intent* only. It maps to a `project_inputs` record (the
 * runtime-variable brief). No generation is performed in this sprint.
 */
final readonly class GenerationRequestData
{
    use ArrayableData;

    /**
     * @param  array<string, mixed>  $payload  full bound runtime variables
     */
    public function __construct(
        public int $project_id,
        public string $deliverable_type,
        public ?int $user_id = null,
        public ?string $platform = null,
        public string $language = 'en',
        public ?string $topic = null,
        public ?string $goal = null,
        public array $payload = [],
        public string $source = 'api',
        public string $type = 'brief',
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            project_id: (int) $data['project_id'],
            deliverable_type: (string) $data['deliverable_type'],
            user_id: isset($data['user_id']) ? (int) $data['user_id'] : null,
            platform: isset($data['platform']) ? (string) $data['platform'] : null,
            language: (string) ($data['language'] ?? DomainHelper::defaultLocale()),
            topic: isset($data['topic']) ? (string) $data['topic'] : null,
            goal: isset($data['goal']) ? (string) $data['goal'] : null,
            payload: (array) ($data['payload'] ?? []),
            source: (string) ($data['source'] ?? 'api'),
            type: (string) ($data['type'] ?? 'brief'),
        );
    }
}
