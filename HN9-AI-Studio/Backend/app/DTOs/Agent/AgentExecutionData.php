<?php

declare(strict_types=1);

namespace App\DTOs\Agent;

use App\DTOs\Concerns\ArrayableData;
use App\Enums\ExecutionStatus;

/**
 * Immutable payload for creating an agent-execution record within a workflow
 * run. Represents the tracking row only — no agent is invoked in this sprint.
 */
final readonly class AgentExecutionData
{
    use ArrayableData;

    /**
     * @param  array<string, mixed>  $input
     */
    public function __construct(
        public int $workflow_run_id,
        public string $agent_key,
        public ?int $ai_provider_id = null,
        public ?string $agent_version = null,
        public string $status = ExecutionStatus::Pending->value,
        public int $attempt = 1,
        public array $input = [],
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            workflow_run_id: (int) $data['workflow_run_id'],
            agent_key: (string) $data['agent_key'],
            ai_provider_id: isset($data['ai_provider_id']) ? (int) $data['ai_provider_id'] : null,
            agent_version: isset($data['agent_version']) ? (string) $data['agent_version'] : null,
            status: (string) ($data['status'] ?? ExecutionStatus::Pending->value),
            attempt: (int) ($data['attempt'] ?? 1),
            input: (array) ($data['input'] ?? []),
        );
    }
}
