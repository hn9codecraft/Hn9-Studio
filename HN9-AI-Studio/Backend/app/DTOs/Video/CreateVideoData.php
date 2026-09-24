<?php

declare(strict_types=1);

namespace App\DTOs\Video;

use App\DTOs\Concerns\ArrayableData;
use App\Enums\VideoAspectRatio;
use App\Enums\VideoDuration;
use App\Enums\VideoSource;
use App\Enums\VideoStatus;

/**
 * Immutable payload for creating a studio video request.
 */
final readonly class CreateVideoData
{
    use ArrayableData;

    /**
     * @param  array<string, mixed>|null  $metadata
     * @param  array<string, mixed>|null  $generation
     */
    public function __construct(
        public int $project_id,
        public string $title,
        public string $prompt,
        public ?string $negative_prompt = null,
        public string $aspect_ratio = VideoAspectRatio::Landscape->value,
        public int $duration = VideoDuration::Five->value,
        public string $status = VideoStatus::Draft->value,
        public string $source = VideoSource::Manual->value,
        public ?int $script_id = null,
        public ?int $image_id = null,
        public ?int $parent_video_id = null,
        public ?int $generated_asset_id = null,
        public ?string $provider = null,
        public ?string $provider_job_id = null,
        public ?array $metadata = null,
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
            prompt: (string) $data['prompt'],
            negative_prompt: array_key_exists('negative_prompt', $data)
                ? ($data['negative_prompt'] !== null ? (string) $data['negative_prompt'] : null)
                : null,
            aspect_ratio: (string) ($data['aspect_ratio'] ?? VideoAspectRatio::Landscape->value),
            duration: (int) ($data['duration'] ?? VideoDuration::Five->value),
            status: (string) ($data['status'] ?? VideoStatus::Draft->value),
            source: (string) ($data['source'] ?? VideoSource::Manual->value),
            script_id: isset($data['script_id']) ? (int) $data['script_id'] : null,
            image_id: isset($data['image_id']) ? (int) $data['image_id'] : null,
            parent_video_id: isset($data['parent_video_id']) ? (int) $data['parent_video_id'] : null,
            generated_asset_id: isset($data['generated_asset_id']) ? (int) $data['generated_asset_id'] : null,
            provider: isset($data['provider']) ? (string) $data['provider'] : null,
            provider_job_id: isset($data['provider_job_id']) ? (string) $data['provider_job_id'] : null,
            metadata: isset($data['metadata']) && is_array($data['metadata']) ? $data['metadata'] : null,
            generation: isset($data['generation']) && is_array($data['generation']) ? $data['generation'] : null,
        );
    }
}
