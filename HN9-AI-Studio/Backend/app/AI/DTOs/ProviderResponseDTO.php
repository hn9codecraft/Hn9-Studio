<?php

declare(strict_types=1);

namespace App\AI\DTOs;

use App\AI\Contracts\ProviderResponseInterface;
use App\AI\Responses\ErrorResponse;
use App\AI\Responses\UsageResponse;
use App\AI\Support\Modality;

/**
 * Immutable, provider-agnostic response envelope. Wraps a concrete modality
 * response with success state, usage accounting and any error, so consumers
 * can handle every provider outcome uniformly.
 */
final readonly class ProviderResponseDTO
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public bool $success,
        public Modality $modality,
        public ?string $providerKey = null,
        public ?string $model = null,
        public array $payload = [],
        public ?UsageResponse $usage = null,
        public ?ErrorResponse $error = null,
    ) {}

    public static function success(
        ProviderResponseInterface $response,
        ?string $providerKey = null,
        ?UsageResponse $usage = null,
    ): self {
        $payload = $response->toArray();

        return new self(
            success: true,
            modality: $response->modality(),
            providerKey: $providerKey,
            model: isset($payload['model']) ? (string) $payload['model'] : null,
            payload: $payload,
            usage: $usage,
        );
    }

    public static function failure(Modality $modality, ErrorResponse $error, ?string $providerKey = null): self
    {
        return new self(
            success: false,
            modality: $modality,
            providerKey: $providerKey,
            error: $error,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'success' => $this->success,
            'modality' => $this->modality->value,
            'provider' => $this->providerKey,
            'model' => $this->model,
            'payload' => $this->payload,
            'usage' => $this->usage?->toArray(),
            'error' => $this->error?->toArray(),
        ];
    }
}
