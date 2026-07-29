<?php

declare(strict_types=1);

namespace App\AI\Providers\Claude;

use App\AI\Responses\UsageResponse;

final readonly class ClaudeUsageCalculator
{
    public function __construct(private ClaudeConfig $config) {}

    /** @param array<string, mixed> $usage */
    public function fromUsage(array $usage, string $model, ?int $executionTimeMs = null): UsageResponse
    {
        $input = (int) ($usage['input_tokens'] ?? 0);
        $output = (int) ($usage['output_tokens'] ?? 0);
        $rate = $this->config->pricing[$model] ?? [];

        return new UsageResponse($input, $output, $input + $output, ($input * (float) ($rate['input'] ?? 0) + $output * (float) ($rate['output'] ?? 0)) / 1_000_000, 'USD', $executionTimeMs);
    }
}
