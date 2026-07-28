<?php

declare(strict_types=1);

namespace App\AI\Responses;

use App\AI\Contracts\ProviderResponseInterface;
use App\AI\Support\Modality;

/**
 * Immutable image-generation result. Carries references (URLs or storage
 * paths) to the produced images, not the binary data.
 */
final readonly class ImageResponse implements ProviderResponseInterface
{
    /**
     * @param  list<string>  $images  URLs or storage paths
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public array $images,
        public ?string $model = null,
        public ?UsageResponse $usage = null,
        public array $raw = [],
    ) {}

    public function modality(): Modality
    {
        return Modality::Image;
    }

    public function count(): int
    {
        return count($this->images);
    }

    public function toArray(): array
    {
        return [
            'modality' => $this->modality()->value,
            'model' => $this->model,
            'images' => $this->images,
            'count' => $this->count(),
            'usage' => $this->usage?->toArray(),
        ];
    }
}
