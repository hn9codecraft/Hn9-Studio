<?php

declare(strict_types=1);

namespace App\AI\Providers\OpenRouter;

use App\AI\Responses\UsageResponse;
use App\AI\Support\AbstractUsageCalculator;

/**
 * Token and cost accounting for OpenRouter.
 *
 * Tokens are read from the vendor's `usage` block. Cost is unusual for this
 * provider: because OpenRouter routes a request to whichever upstream endpoint
 * it selects, the settled charge can differ from any statically configured rate.
 * When usage accounting is enabled OpenRouter returns that settled charge, and
 * it is preferred over the local calculation; otherwise the shared
 * per-million-token arithmetic applies the configured rates, and an unpriced
 * model simply yields zero.
 */
final readonly class OpenRouterUsageCalculator extends AbstractUsageCalculator
{
    public function __construct(OpenRouterConfig $config)
    {
        parent::__construct($config->pricing);
    }

    /**
     * @param  array<string, mixed>  $usage  A chat-completions `usage` block.
     */
    public function fromUsage(array $usage, string $model, ?int $executionTimeMs = null): UsageResponse
    {
        $priced = $this->priced(
            $model,
            (int) ($usage['prompt_tokens'] ?? 0),
            (int) ($usage['completion_tokens'] ?? 0),
            isset($usage['total_tokens']) ? (int) $usage['total_tokens'] : null,
            $executionTimeMs,
        );

        $reported = $this->reportedCost($usage);

        if ($reported === null) {
            return $priced;
        }

        return new UsageResponse(
            promptTokens: $priced->promptTokens,
            completionTokens: $priced->completionTokens,
            totalTokens: $priced->totalTokens,
            cost: $reported,
            currency: $priced->currency,
            executionTimeMs: $priced->executionTimeMs,
        );
    }

    /**
     * The charge OpenRouter settled for the call, when it reports one.
     *
     * @param  array<string, mixed>  $usage
     */
    private function reportedCost(array $usage): ?float
    {
        $cost = $usage['cost'] ?? null;

        return is_int($cost) || is_float($cost) ? (float) $cost : null;
    }
}
