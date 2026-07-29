<?php

declare(strict_types=1);

namespace App\AI\Providers\Gemini;

use App\AI\Responses\UsageResponse;
use App\AI\Support\AbstractUsageCalculator;

final readonly class GeminiUsageCalculator extends AbstractUsageCalculator
{
    public function __construct(GeminiConfig $config)
    {
        parent::__construct($config->pricing);
    }

    /**
     * Translate Gemini's `usageMetadata` block into shared usage accounting.
     *
     * Thinking tokens (`thoughtsTokenCount`) are billed at the output rate, so
     * they are counted as completion tokens. `totalTokenCount` is authoritative
     * when present.
     *
     * @param  array<string, mixed>  $usage
     */
    public function fromUsageMetadata(array $usage, string $model, ?int $executionTimeMs = null): UsageResponse
    {
        $prompt = (int) ($usage['promptTokenCount'] ?? 0);
        $completion = (int) ($usage['candidatesTokenCount'] ?? 0) + (int) ($usage['thoughtsTokenCount'] ?? 0);
        $total = isset($usage['totalTokenCount']) ? (int) $usage['totalTokenCount'] : null;

        return $this->priced($model, $prompt, $completion, $total, $executionTimeMs);
    }
}
