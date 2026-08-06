<?php

declare(strict_types=1);

namespace App\AI\Execution;

use App\AI\Contracts\ProviderResponseInterface;
use App\AI\DTOs\ProviderResponseDTO;
use App\AI\Routing\RoutingPlan;
use App\AI\Support\Modality;

/**
 * The outcome of a dispatch: the response, the provider that produced it, and
 * what it took to get there.
 *
 * The response is the same typed object the provider returned, so nothing about
 * the existing modality responses changes for callers. Everything alongside it
 * is observability — which providers were tried, how many retries and
 * fallbacks were spent, and what the call is estimated to have cost.
 */
final readonly class DispatchResult
{
    /**
     * @param  list<AttemptRecord>  $attempts
     */
    public function __construct(
        public string $providerKey,
        public ProviderResponseInterface $response,
        public Modality $modality,
        public int $durationMs = 0,
        public int $retries = 0,
        public int $fallbacks = 0,
        public float $estimatedCost = 0.0,
        public array $attempts = [],
        public ?RoutingPlan $plan = null,
    ) {}

    /**
     * Whether the answer came from somewhere other than the plan's first choice.
     */
    public function usedFallback(): bool
    {
        return $this->fallbacks > 0;
    }

    /**
     * The response in the platform's provider-agnostic envelope.
     */
    public function envelope(): ProviderResponseDTO
    {
        return ProviderResponseDTO::success($this->response, $this->providerKey);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'provider' => $this->providerKey,
            'modality' => $this->modality->value,
            'duration_ms' => $this->durationMs,
            'retries' => $this->retries,
            'fallbacks' => $this->fallbacks,
            'estimated_cost' => round($this->estimatedCost, 6),
            'attempts' => array_map(
                static fn (AttemptRecord $attempt): array => $attempt->toArray(),
                $this->attempts,
            ),
            'plan' => $this->plan?->keys() ?? [],
            'response' => $this->response->toArray(),
        ];
    }
}
