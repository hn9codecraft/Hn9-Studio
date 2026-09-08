<?php

declare(strict_types=1);

namespace App\AI\Responses;

/**
 * Immutable token/cost accounting for a single provider call. Emitted alongside
 * a modality response so the pipeline can record spend without parsing raw
 * provider payloads.
 *
 * Cost is null unless a provider reported a settled charge or a non-empty
 * configured pricing rule produced it. Empty pricing must not become 0.0 spend.
 */
final readonly class UsageResponse
{
    public function __construct(
        public int $promptTokens = 0,
        public int $completionTokens = 0,
        public int $totalTokens = 0,
        public ?float $cost = null,
        public string $currency = 'USD',
        public ?int $executionTimeMs = null,
        public ?string $costSource = null,
    ) {}

    public static function empty(): self
    {
        return new self;
    }

    /**
     * Routing estimates may treat unknown cost as 0; billed persistence must not.
     */
    public function routingEstimate(): float
    {
        return $this->cost ?? 0.0;
    }

    public function hasBilledCost(): bool
    {
        return $this->cost !== null && $this->costSource !== null;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $prompt = (int) ($data['prompt_tokens'] ?? 0);
        $completion = (int) ($data['completion_tokens'] ?? 0);
        $total = (int) ($data['total_tokens'] ?? ($prompt + $completion));
        $source = isset($data['cost_source']) ? (string) $data['cost_source'] : null;
        $hasCost = array_key_exists('cost', $data) && $data['cost'] !== null && $source !== null;

        return new self(
            promptTokens: $prompt,
            completionTokens: $completion,
            totalTokens: $total,
            cost: $hasCost ? (float) $data['cost'] : null,
            currency: (string) ($data['currency'] ?? 'USD'),
            executionTimeMs: isset($data['execution_time_ms']) ? (int) $data['execution_time_ms'] : null,
            costSource: $hasCost ? $source : null,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'prompt_tokens' => $this->promptTokens,
            'completion_tokens' => $this->completionTokens,
            'total_tokens' => $this->totalTokens,
            'cost' => $this->cost,
            'currency' => $this->currency,
            'execution_time_ms' => $this->executionTimeMs,
            'cost_source' => $this->costSource,
        ];
    }
}
