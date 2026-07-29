<?php

declare(strict_types=1);

namespace App\AI\Responses;

/**
 * Immutable token/cost accounting for a single provider call. Emitted alongside
 * a modality response so the pipeline can record spend without parsing raw
 * provider payloads.
 */
final readonly class UsageResponse
{
    public function __construct(
        public int $promptTokens = 0,
        public int $completionTokens = 0,
        public int $totalTokens = 0,
        public float $cost = 0.0,
        public string $currency = 'USD',
        public ?int $executionTimeMs = null,
    ) {}

    public static function empty(): self
    {
        return new self;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $prompt = (int) ($data['prompt_tokens'] ?? 0);
        $completion = (int) ($data['completion_tokens'] ?? 0);
        $total = (int) ($data['total_tokens'] ?? ($prompt + $completion));

        return new self(
            promptTokens: $prompt,
            completionTokens: $completion,
            totalTokens: $total,
            cost: (float) ($data['cost'] ?? 0.0),
            currency: (string) ($data['currency'] ?? 'USD'),
            executionTimeMs: isset($data['execution_time_ms']) ? (int) $data['execution_time_ms'] : null,
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
        ];
    }
}
