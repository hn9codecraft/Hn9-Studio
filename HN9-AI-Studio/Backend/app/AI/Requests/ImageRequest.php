<?php

declare(strict_types=1);

namespace App\AI\Requests;

use App\AI\Contracts\ProviderRequestInterface;
use App\AI\Support\Modality;

/**
 * Immutable image-generation request. Describes intent only.
 */
final readonly class ImageRequest implements ProviderRequestInterface
{
    /**
     * @param  array<string, mixed>  $options
     */
    public function __construct(
        public string $prompt,
        public ?string $model = null,
        public ?string $negativePrompt = null,
        public int $count = 1,
        public ?string $size = null,
        public ?string $quality = null,
        public ?string $style = null,
        public ?string $format = null,
        public ?int $seed = null,
        public array $options = [],
    ) {}

    public function modality(): Modality
    {
        return Modality::Image;
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
            'negative_prompt' => $this->negativePrompt,
            'count' => $this->count,
            'size' => $this->size,
            'quality' => $this->quality,
            'style' => $this->style,
            'format' => $this->format,
            'seed' => $this->seed,
            'options' => $this->options,
        ];
    }
}
