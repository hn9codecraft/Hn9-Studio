<?php

declare(strict_types=1);

namespace App\DTOs\Content;

use App\DTOs\Concerns\ArrayableData;
use App\Enums\ProjectStatus;
use App\Support\DomainHelper;

/**
 * Immutable payload describing a generated textual content record (script,
 * caption, blog copy, SEO metadata, subtitle). Stores the produced text and
 * its structured shape — it does not produce the text.
 */
final readonly class ContentData
{
    use ArrayableData;

    /**
     * @param  array<string, mixed>  $structured
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public int $project_id,
        public string $type,
        public ?int $workflow_run_id = null,
        public ?int $agent_execution_id = null,
        public ?string $channel = null,
        public string $language = 'en',
        public ?string $title = null,
        public ?string $body = null,
        public array $structured = [],
        public string $status = ProjectStatus::Draft->value,
        public int $version = 1,
        public array $metadata = [],
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            project_id: (int) $data['project_id'],
            type: (string) $data['type'],
            workflow_run_id: isset($data['workflow_run_id']) ? (int) $data['workflow_run_id'] : null,
            agent_execution_id: isset($data['agent_execution_id']) ? (int) $data['agent_execution_id'] : null,
            channel: isset($data['channel']) ? (string) $data['channel'] : null,
            language: (string) ($data['language'] ?? DomainHelper::defaultLocale()),
            title: isset($data['title']) ? (string) $data['title'] : null,
            body: isset($data['body']) ? (string) $data['body'] : null,
            structured: (array) ($data['structured'] ?? []),
            status: (string) ($data['status'] ?? ProjectStatus::Draft->value),
            version: (int) ($data['version'] ?? 1),
            metadata: (array) ($data['metadata'] ?? []),
        );
    }
}
