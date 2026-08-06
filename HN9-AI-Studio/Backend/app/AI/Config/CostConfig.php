<?php

declare(strict_types=1);

namespace App\AI\Config;

use App\AI\Support\CostStrategy;

/**
 * Cost-optimisation settings: the default cost preference and the per-request
 * budget a candidate's estimate must fit inside.
 *
 * Estimation is opt-in because a provider's estimator may consult a remote
 * tokenizer; with `enabled` false the platform never asks for an estimate and
 * cost simply does not participate in selection.
 */
final readonly class CostConfig
{
    public function __construct(
        public bool $enabled = false,
        public CostStrategy $strategy = CostStrategy::Balanced,
        public ?float $budget = null,
        public string $currency = 'USD',
    ) {}

    public static function fromReader(ConfigReader $reader): self
    {
        return new self(
            enabled: $reader->bool('enabled', false),
            strategy: CostStrategy::tryFrom($reader->string('strategy', CostStrategy::Balanced->value))
                ?? CostStrategy::Balanced,
            budget: $reader->nullableFloat('budget'),
            currency: $reader->string('currency', 'USD'),
        );
    }

    /**
     * Whether a cost estimate is worth computing for this dispatch.
     */
    public function estimatesFor(CostStrategy $strategy, ?float $budget): bool
    {
        if (! $this->enabled) {
            return false;
        }

        return $budget !== null || $this->budget !== null || $strategy->needsEstimate();
    }

    /**
     * The effective budget: a caller's ceiling overrides the configured one.
     */
    public function budgetFor(?float $override = null): ?float
    {
        return $override ?? $this->budget;
    }
}
