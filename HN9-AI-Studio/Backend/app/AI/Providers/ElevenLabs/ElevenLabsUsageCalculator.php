<?php

declare(strict_types=1);

namespace App\AI\Providers\ElevenLabs;

use App\AI\Responses\UsageResponse;
use App\AI\Support\AbstractUsageCalculator;

/**
 * Usage and cost accounting for ElevenLabs.
 *
 * The vendor meters credits, and credits derive from the characters submitted:
 * one character costs one credit on the standard models, and less on the faster
 * ones. That ratio is configured per model rather than compiled in, so a change
 * to the vendor's plans is a configuration change.
 *
 * Cost then reuses the shared per-unit arithmetic unchanged, with credits as
 * the billed unit in place of tokens: configured rates are quoted per million
 * credits, and an unpriced model yields zero rather than an invented figure.
 * The resulting {@see UsageResponse} carries credits in the input-unit field,
 * keeping spend accounting uniform across every provider; the underlying
 * character count travels on the response's raw payload.
 */
final readonly class ElevenLabsUsageCalculator extends AbstractUsageCalculator
{
    /**
     * Standard models bill one credit per character.
     */
    private const DEFAULT_CREDIT_MULTIPLIER = 1.0;

    public function __construct(private ElevenLabsConfig $config)
    {
        parent::__construct($config->pricing);
    }

    public function fromCharacters(int $characters, string $model, ?int $executionTimeMs = null): UsageResponse
    {
        return $this->priced(
            $model,
            $this->credits($characters, $model),
            0,
            executionTimeMs: $executionTimeMs,
        );
    }

    /**
     * The credits a character count consumes on a given model. Rounded up: the
     * vendor does not sell fractional credits.
     */
    public function credits(int $characters, string $model): int
    {
        $multiplier = (float) ($this->config->creditMultipliers[$model] ?? self::DEFAULT_CREDIT_MULTIPLIER);

        return (int) ceil($characters * $multiplier);
    }
}
