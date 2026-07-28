<?php

declare(strict_types=1);

namespace App\DTOs\Prompt;

use App\DTOs\Concerns\ArrayableData;
use App\Enums\ExecutionStatus;

/**
 * Immutable payload for creating a prompt-execution record. Captures which
 * template/variables an execution will use — the actual prompt rendering and
 * model call belong to a later sprint.
 */
final readonly class PromptExecutionData
{
    use ArrayableData;

    /**
     * @param  array<string, mixed>  $variables
     */
    public function __construct(
        public int $agent_execution_id,
        public string $template_key,
        public ?int $ai_provider_id = null,
        public ?string $template_version = null,
        public ?string $model = null,
        public string $status = ExecutionStatus::Pending->value,
        public array $variables = [],
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            agent_execution_id: (int) $data['agent_execution_id'],
            template_key: (string) $data['template_key'],
            ai_provider_id: isset($data['ai_provider_id']) ? (int) $data['ai_provider_id'] : null,
            template_version: isset($data['template_version']) ? (string) $data['template_version'] : null,
            model: isset($data['model']) ? (string) $data['model'] : null,
            status: (string) ($data['status'] ?? ExecutionStatus::Pending->value),
            variables: (array) ($data['variables'] ?? []),
        );
    }
}
