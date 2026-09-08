<?php

declare(strict_types=1);

namespace App\AI\Support;

use App\AI\Responses\UsageResponse;
use App\Enums\CostSource;

/**
 * Shared token/cost arithmetic for provider adapters. Subclasses translate their
 * vendor's usage payload into prompt/completion counts; the pricing model —
 * per-million-token rates supplied by configuration — is applied here once.
 *
 * Unpriced models yield a null cost, not invented spend.
 */
abstract readonly class AbstractUsageCalculator
{
    /**
     * Rates in configuration are quoted per million tokens.
     */
    protected const TOKENS_PER_PRICE_UNIT = 1_000_000;

    protected const CURRENCY = 'USD';

    /**
     * @param  array<string, array{input?: float|int, output?: float|int}>  $pricing
     */
    public function __construct(protected array $pricing) {}

    /**
     * Build a usage response, pricing the tokens only when a rate exists.
     */
    protected function priced(
        string $model,
        int $promptTokens,
        int $completionTokens,
        ?int $totalTokens = null,
        ?int $executionTimeMs = null,
    ): UsageResponse {
        $rate = $this->pricing[$model] ?? [];
        $hasPricing = array_key_exists('input', $rate) || array_key_exists('output', $rate);

        $cost = null;
        $costSource = null;

        if ($hasPricing) {
            $cost = ($promptTokens * (float) ($rate['input'] ?? 0) + $completionTokens * (float) ($rate['output'] ?? 0))
                / self::TOKENS_PER_PRICE_UNIT;
            $costSource = CostSource::ConfiguredPricing->value;
        }

        return new UsageResponse(
            promptTokens: $promptTokens,
            completionTokens: $completionTokens,
            totalTokens: $totalTokens ?? $promptTokens + $completionTokens,
            cost: $cost,
            currency: self::CURRENCY,
            executionTimeMs: $executionTimeMs,
            costSource: $costSource,
        );
    }
}
