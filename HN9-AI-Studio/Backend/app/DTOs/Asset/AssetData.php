<?php

declare(strict_types=1);

namespace App\DTOs\Asset;

use App\DTOs\Concerns\ArrayableData;
use App\Enums\ExecutionStatus;

/**
 * Immutable payload describing a generated media asset record. The binary
 * file(s) are tracked separately in `media_files`; this DTO is the logical
 * asset plus its generation metadata.
 */
final readonly class AssetData
{
    use ArrayableData;

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public int $project_id,
        public string $type,
        public ?int $generated_content_id = null,
        public ?int $workflow_run_id = null,
        public ?int $agent_execution_id = null,
        public ?string $provider = null,
        public string $status = ExecutionStatus::Pending->value,
        public ?string $prompt = null,
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
            generated_content_id: isset($data['generated_content_id']) ? (int) $data['generated_content_id'] : null,
            workflow_run_id: isset($data['workflow_run_id']) ? (int) $data['workflow_run_id'] : null,
            agent_execution_id: isset($data['agent_execution_id']) ? (int) $data['agent_execution_id'] : null,
            provider: isset($data['provider']) ? (string) $data['provider'] : null,
            status: (string) ($data['status'] ?? ExecutionStatus::Pending->value),
            prompt: isset($data['prompt']) ? (string) $data['prompt'] : null,
            metadata: (array) ($data['metadata'] ?? []),
        );
    }
}
