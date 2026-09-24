<?php

declare(strict_types=1);

namespace App\DTOs\Script;

use App\DTOs\Concerns\ArrayableData;
use App\Enums\ScriptSource;
use App\Enums\ScriptStatus;

/**
 * Immutable payload for creating a studio script.
 */
final readonly class CreateScriptData
{
    use ArrayableData;

    /**
     * @param  array<string, mixed>|null  $generation
     */
    public function __construct(
        public int $project_id,
        public string $title,
        public ?string $body = null,
        public string $status = ScriptStatus::Draft->value,
        public string $source = ScriptSource::Manual->value,
        public ?int $generated_content_id = null,
        public ?int $parent_script_id = null,
        public ?array $generation = null,
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
            source: (string) ($data['source'] ?? ScriptSource::Manual->value),
            generated_content_id: isset($data['generated_content_id']) ? (int) $data['generated_content_id'] : null,
            parent_script_id: isset($data['parent_script_id']) ? (int) $data['parent_script_id'] : null,
            generation: isset($data['generation']) && is_array($data['generation']) ? $data['generation'] : null,
        );
    }
}
