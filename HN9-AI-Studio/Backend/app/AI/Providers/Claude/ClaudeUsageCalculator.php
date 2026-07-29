<?php

declare(strict_types=1);

namespace App\AI\Providers\Claude;

use App\AI\Responses\UsageResponse;
use App\AI\Support\AbstractUsageCalculator;

final readonly class ClaudeUsageCalculator extends AbstractUsageCalculator
{
    public function __construct(ClaudeConfig $config)
    {
        parent::__construct($config->pricing);
    }

    /**
     * @param  array<string, mixed>  $usage  Anthropic `usage` block.
     */
    public function fromUsage(array $usage, string $model, ?int $executionTimeMs = null): UsageResponse
    {
        return $this->priced(
            $model,
            (int) ($usage['input_tokens'] ?? 0),
            (int) ($usage['output_tokens'] ?? 0),
            executionTimeMs: $executionTimeMs,
        );
    }
}
