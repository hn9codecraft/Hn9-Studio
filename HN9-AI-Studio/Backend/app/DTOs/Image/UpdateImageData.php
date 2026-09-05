<?php

declare(strict_types=1);

namespace App\DTOs\Image;

use App\DTOs\Concerns\ArrayableData;

/**
 * Immutable payload for a partial image request update. Nulls are dropped so
 * only supplied fields are persisted.
 */
final readonly class UpdateImageData
{
    use ArrayableData;

    public function __construct(
        public ?string $title = null,
        public ?string $prompt = null,
        public ?string $negative_prompt = null,
        public ?string $aspect_ratio = null,
        public ?string $status = null,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            title: isset($data['title']) ? (string) $data['title'] : null,
            prompt: isset($data['prompt']) ? (string) $data['prompt'] : null,
            negative_prompt: array_key_exists('negative_prompt', $data)
                ? ($data['negative_prompt'] !== null ? (string) $data['negative_prompt'] : null)
                : null,
            aspect_ratio: isset($data['aspect_ratio']) ? (string) $data['aspect_ratio'] : null,
            status: isset($data['status']) ? (string) $data['status'] : null,
        );
    }
}
