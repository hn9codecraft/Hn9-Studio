<?php

declare(strict_types=1);

namespace App\AI\Requests;

use App\AI\Contracts\ProviderRequestInterface;
use App\AI\Support\Modality;

/**
 * Immutable voice/text-to-speech request. Describes intent only.
 */
final readonly class VoiceRequest implements ProviderRequestInterface
{
    /**
     * @param  array<string, mixed>  $options
     */
    public function __construct(
        public string $input,
        public ?string $model = null,
        public ?string $voice = null,
        public ?string $language = null,
        public ?string $format = null,
        public ?float $speed = null,
        public array $options = [],
    ) {}

    public function modality(): Modality
    {
        return Modality::Voice;
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
            'input' => $this->input,
            'voice' => $this->voice,
            'language' => $this->language,
            'format' => $this->format,
            'speed' => $this->speed,
            'options' => $this->options,
        ];
    }
}
