<?php

declare(strict_types=1);

namespace App\AI\Providers\OpenAI;

use App\AI\Responses\UsageResponse;

final readonly class OpenAIUsageCalculator
{
    public function __construct(private OpenAIConfig $config) {}

    /** @param array<string, mixed> $usage */
    public function fromUsage(array $usage, string $model, ?int $executionTimeMs = null): UsageResponse
    {
        $prompt = (int) ($usage['input_tokens'] ?? $usage['prompt_tokens'] ?? 0);
        $completion = (int) ($usage['output_tokens'] ?? $usage['completion_tokens'] ?? 0);
        $total = (int) ($usage['total_tokens'] ?? ($prompt + $completion));
        $rate = $this->config->pricing[$model] ?? [];
        $cost = ($prompt * ((float) ($rate['input'] ?? 0)) + $completion * ((float) ($rate['output'] ?? 0))) / 1_000_000;

        return new UsageResponse($prompt, $completion, $total, $cost, 'USD', $executionTimeMs);
    }
}
