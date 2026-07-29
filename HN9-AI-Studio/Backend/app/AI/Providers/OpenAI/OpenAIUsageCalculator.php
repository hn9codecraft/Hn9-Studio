<?php

declare(strict_types=1);

namespace App\AI\Providers\OpenAI;

use App\AI\Responses\UsageResponse;
use App\AI\Support\AbstractUsageCalculator;

final readonly class OpenAIUsageCalculator extends AbstractUsageCalculator
{
    public function __construct(OpenAIConfig $config)
    {
        parent::__construct($config->pricing);
    }

    /**
     * Accepts both the Responses API (`input_tokens`) and Chat Completions
     * (`prompt_tokens`) spellings.
     *
     * @param  array<string, mixed>  $usage
     */
    public function fromUsage(array $usage, string $model, ?int $executionTimeMs = null): UsageResponse
    {
        $prompt = (int) ($usage['input_tokens'] ?? $usage['prompt_tokens'] ?? 0);
        $completion = (int) ($usage['output_tokens'] ?? $usage['completion_tokens'] ?? 0);
        $total = isset($usage['total_tokens']) ? (int) $usage['total_tokens'] : null;

        return $this->priced($model, $prompt, $completion, $total, $executionTimeMs);
    }
}
