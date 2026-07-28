<?php

declare(strict_types=1);

namespace App\AI\Responses;

use App\AI\Contracts\ProviderResponseInterface;
use App\AI\Support\Modality;

/**
 * Immutable video-generation result. Carries a reference (URL or storage path)
 * to the produced video, not the binary data.
 */
final readonly class VideoResponse implements ProviderResponseInterface
{
    /**
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public string $video,
        public ?string $model = null,
        public ?float $durationSeconds = null,
        public ?string $format = null,
        public ?UsageResponse $usage = null,
        public array $raw = [],
    ) {}

    public function modality(): Modality
    {
        return Modality::Video;
    }

    public function toArray(): array
    {
        return [
            'modality' => $this->modality()->value,
            'model' => $this->model,
            'video' => $this->video,
            'duration_seconds' => $this->durationSeconds,
            'format' => $this->format,
            'usage' => $this->usage?->toArray(),
        ];
    }
}
