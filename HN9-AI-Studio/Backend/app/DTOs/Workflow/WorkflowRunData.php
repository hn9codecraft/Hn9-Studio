<?php

declare(strict_types=1);

namespace App\DTOs\Workflow;

use App\DTOs\Concerns\ArrayableData;
use App\Enums\WorkflowStatus;

/**
 * Immutable payload for creating a workflow-run record. Represents the intent
 * to track a pipeline run; the execution engine itself is a later sprint.
 */
final readonly class WorkflowRunData
{
    use ArrayableData;

    /**
     * @param  array<string, mixed>  $context
     */
    public function __construct(
        public int $project_id,
        public string $workflow_key,
        public ?int $user_id = null,
        public string $status = WorkflowStatus::Pending->value,
        public ?string $current_stage = null,
        public ?int $total_steps = null,
        public array $context = [],
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            project_id: (int) $data['project_id'],
            workflow_key: (string) $data['workflow_key'],
            user_id: isset($data['user_id']) ? (int) $data['user_id'] : null,
            status: (string) ($data['status'] ?? WorkflowStatus::Pending->value),
            current_stage: isset($data['current_stage']) ? (string) $data['current_stage'] : null,
            total_steps: isset($data['total_steps']) ? (int) $data['total_steps'] : null,
            context: (array) ($data['context'] ?? []),
        );
    }
}
