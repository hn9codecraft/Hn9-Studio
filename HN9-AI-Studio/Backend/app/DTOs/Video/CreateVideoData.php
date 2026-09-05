<?php

declare(strict_types=1);

namespace App\DTOs\Video;

use App\DTOs\Concerns\ArrayableData;
use App\Enums\VideoAspectRatio;
use App\Enums\VideoDuration;
use App\Enums\VideoStatus;

/**
 * Immutable payload for creating a studio video request.
 */
final readonly class CreateVideoData
{
    use ArrayableData;

    public function __construct(
        public int $project_id,
        public string $title,
        public string $prompt,
        public ?string $negative_prompt = null,
        public string $aspect_ratio = VideoAspectRatio::Landscape->value,
        public int $duration = VideoDuration::Five->value,
        public string $status = VideoStatus::Draft->value,
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
            aspect_ratio: (string) ($data['aspect_ratio'] ?? VideoAspectRatio::Landscape->value),
            duration: (int) ($data['duration'] ?? VideoDuration::Five->value),
            status: (string) ($data['status'] ?? VideoStatus::Draft->value),
        );
    }
}
