<?php

declare(strict_types=1);

namespace App\DTOs\Script;

use App\DTOs\Concerns\ArrayableData;

/**
 * Immutable payload for a partial script update. Nulls are dropped so only
 * supplied fields are persisted.
 */
final readonly class UpdateScriptData
{
    use ArrayableData;

    public function __construct(
        public ?string $title = null,
        public ?string $body = null,
        public ?string $status = null,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            title: isset($data['title']) ? (string) $data['title'] : null,
            body: array_key_exists('body', $data) ? ($data['body'] !== null ? (string) $data['body'] : null) : null,
            status: isset($data['status']) ? (string) $data['status'] : null,
        );
    }
}
