<?php

declare(strict_types=1);

namespace App\DTOs\Image;

use App\DTOs\Concerns\ArrayableData;
use App\Enums\ImageAspectRatio;
use App\Enums\ImageStatus;

/**
 * Immutable payload for creating a studio image request.
 */
final readonly class CreateImageData
{
    use ArrayableData;

    public function __construct(
        public int $project_id,
        public string $title,
        public string $prompt,
        public ?string $negative_prompt = null,
        public string $aspect_ratio = ImageAspectRatio::Square->value,
        public string $status = ImageStatus::Draft->value,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            project_id: (int) $data['project_id'],
            title: (string) $data['title'],
            prompt: (string) $data['prompt'],
            negative_prompt: array_key_exists('negative_prompt', $data)
                ? ($data['negative_prompt'] !== null ? (string) $data['negative_prompt'] : null)
                : null,
            aspect_ratio: (string) ($data['aspect_ratio'] ?? ImageAspectRatio::Square->value),
            status: (string) ($data['status'] ?? ImageStatus::Draft->value),
        );
    }
}
