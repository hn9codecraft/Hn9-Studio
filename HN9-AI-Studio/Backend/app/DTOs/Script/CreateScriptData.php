<?php

declare(strict_types=1);

namespace App\DTOs\Script;

use App\DTOs\Concerns\ArrayableData;
use App\Enums\ScriptStatus;

/**
 * Immutable payload for creating a studio script.
 */
final readonly class CreateScriptData
{
    use ArrayableData;

    public function __construct(
        public int $project_id,
        public string $title,
        public ?string $body = null,
        public string $status = ScriptStatus::Draft->value,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            project_id: (int) $data['project_id'],
            title: (string) $data['title'],
            body: array_key_exists('body', $data) ? ($data['body'] !== null ? (string) $data['body'] : null) : null,
            status: (string) ($data['status'] ?? ScriptStatus::Draft->value),
        );
    }
}
