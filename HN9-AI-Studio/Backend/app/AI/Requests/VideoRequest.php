<?php

declare(strict_types=1);

namespace App\AI\Requests;

use App\AI\Contracts\ProviderRequestInterface;
use App\AI\Support\Modality;

/**
 * Immutable video-generation request. Describes intent only.
 */
final readonly class VideoRequest implements ProviderRequestInterface
{
    /**
     * @param  array<string, mixed>  $options
     */
    public function __construct(
        public string $prompt,
        public ?string $model = null,
        public ?float $durationSeconds = null,
        public ?string $resolution = null,
        public ?int $fps = null,
        public ?string $aspectRatio = null,
        public ?string $format = null,
        public array $options = [],
    ) {}

    public function modality(): Modality
    {
        return Modality::Video;
    }

    public function model(): ?string
    {
        return $this->model;
    }

    public function toArray(): array
    {
        return [
            'modality' => $this->modality()->value,
            'model' => $this->model,
            'prompt' => $this->prompt,
            'duration_seconds' => $this->durationSeconds,
            'resolution' => $this->resolution,
            'fps' => $this->fps,
            'aspect_ratio' => $this->aspectRatio,
            'format' => $this->format,
            'options' => $this->options,
        ];
    }
}
