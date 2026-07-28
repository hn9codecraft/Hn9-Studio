<?php

declare(strict_types=1);

namespace App\AI\Responses;

use App\AI\Contracts\ProviderResponseInterface;
use App\AI\Support\Modality;

/**
 * Immutable text-generation result.
 */
final readonly class TextResponse implements ProviderResponseInterface
{
    /**
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public string $text,
        public ?string $model = null,
        public ?string $finishReason = null,
        public ?UsageResponse $usage = null,
        public array $raw = [],
    ) {}

    public function modality(): Modality
    {
        return Modality::Text;
    }

    public function toArray(): array
    {
        return [
            'modality' => $this->modality()->value,
            'model' => $this->model,
            'text' => $this->text,
            'finish_reason' => $this->finishReason,
            'usage' => $this->usage?->toArray(),
        ];
    }
}
