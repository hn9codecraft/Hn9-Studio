<?php

declare(strict_types=1);

namespace App\AI\DTOs;

use App\AI\Contracts\ProviderRequestInterface;
use App\AI\Support\Modality;

/**
 * Immutable, provider-agnostic request envelope. Normalises any typed request
 * ({@see ProviderRequestInterface}) into a flat shape for cost estimation,
 * token counting and telemetry.
 */
final readonly class ProviderRequestDTO
{
    /**
     * @param  array<string, mixed>  $parameters
     */
    public function __construct(
        public Modality $modality,
        public ?string $model = null,
        public ?string $providerKey = null,
        public array $parameters = [],
    ) {}

    public static function fromRequest(ProviderRequestInterface $request, ?string $providerKey = null): self
    {
        return new self(
            modality: $request->modality(),
            model: $request->model(),
            providerKey: $providerKey,
            parameters: $request->toArray(),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'modality' => $this->modality->value,
            'model' => $this->model,
            'provider' => $this->providerKey,
            'parameters' => $this->parameters,
        ];
    }
}
