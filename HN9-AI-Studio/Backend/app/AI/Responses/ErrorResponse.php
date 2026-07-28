<?php

declare(strict_types=1);

namespace App\AI\Responses;

/**
 * Immutable representation of a provider failure, normalised across providers
 * so callers handle errors uniformly rather than parsing raw payloads.
 */
final readonly class ErrorResponse
{
    /**
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public string $message,
        public string $code = 'provider_error',
        public ?string $provider = null,
        public bool $retryable = false,
        public array $raw = [],
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'message' => $this->message,
            'code' => $this->code,
            'provider' => $this->provider,
            'retryable' => $this->retryable,
        ];
    }
}
