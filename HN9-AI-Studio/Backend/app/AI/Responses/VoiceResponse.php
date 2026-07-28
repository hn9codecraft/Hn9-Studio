<?php

declare(strict_types=1);

namespace App\AI\Responses;

use App\AI\Contracts\ProviderResponseInterface;
use App\AI\Support\Modality;

/**
 * Immutable voice/text-to-speech result. Carries a reference (URL or storage
 * path) to the produced audio, not the binary data.
 */
final readonly class VoiceResponse implements ProviderResponseInterface
{
    /**
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public string $audio,
        public ?string $model = null,
        public ?string $voice = null,
        public ?string $format = null,
        public ?float $durationSeconds = null,
        public ?UsageResponse $usage = null,
        public array $raw = [],
    ) {}

    public function modality(): Modality
    {
        return Modality::Voice;
    }

    public function toArray(): array
    {
        return [
            'modality' => $this->modality()->value,
            'model' => $this->model,
            'audio' => $this->audio,
            'voice' => $this->voice,
            'format' => $this->format,
            'duration_seconds' => $this->durationSeconds,
            'usage' => $this->usage?->toArray(),
        ];
    }
}
