<?php

declare(strict_types=1);

namespace App\AI\DTOs;

use App\AI\Support\HealthStatus;

/**
 * Immutable outcome of a provider health check. This sprint provides the
 * structure and the aggregation harness; concrete probes arrive with the
 * provider implementations.
 */
final readonly class ProviderHealthDTO
{
    /**
     * @param  array<string, mixed>  $details
     */
    public function __construct(
        public string $key,
        public HealthStatus $status,
        public ?int $latencyMs = null,
        public ?string $message = null,
        public ?string $checkedAt = null,
        public array $details = [],
    ) {}

    public static function healthy(string $key, ?int $latencyMs = null, ?string $checkedAt = null): self
    {
        return new self($key, HealthStatus::Healthy, $latencyMs, null, $checkedAt);
    }

    public static function unavailable(string $key, string $message, ?string $checkedAt = null): self
    {
        return new self($key, HealthStatus::Unavailable, null, $message, $checkedAt);
    }

    public static function unknown(string $key, ?string $checkedAt = null): self
    {
        return new self($key, HealthStatus::Unknown, null, 'No health probe available.', $checkedAt);
    }

    public function isOperational(): bool
    {
        return $this->status->isOperational();
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'status' => $this->status->value,
            'latency_ms' => $this->latencyMs,
            'message' => $this->message,
            'checked_at' => $this->checkedAt,
            'details' => $this->details,
        ];
    }
}
