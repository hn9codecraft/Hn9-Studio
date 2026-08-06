<?php

declare(strict_types=1);

namespace App\AI\Config;

/**
 * Provider selection settings: the active strategy, the weights the balanced
 * strategy applies to each normalised signal, health shaping and the fallback
 * chain.
 *
 * The strategy is a registry key, not a class name or a branch, so a new
 * strategy is adopted by registering it and naming it here.
 */
final readonly class RoutingConfig
{
    public const DEFAULT_STRATEGY = 'balanced';

    /**
     * @param  list<string>  $preferred  Operator preference, highest first.
     * @param  array<string, float>  $weights  Signal name => relative importance.
     */
    public function __construct(
        public string $strategy = self::DEFAULT_STRATEGY,
        public array $preferred = [],
        public array $weights = [],
        public HealthRoutingConfig $health = new HealthRoutingConfig,
        public FallbackConfig $fallback = new FallbackConfig,
    ) {}

    public static function fromReader(ConfigReader $reader): self
    {
        return new self(
            strategy: $reader->string('strategy', self::DEFAULT_STRATEGY),
            preferred: $reader->stringList('preferred'),
            weights: $reader->floatMap('weights'),
            health: HealthRoutingConfig::fromReader($reader->section('health')),
            fallback: FallbackConfig::fromReader($reader->section('fallback')),
        );
    }

    /**
     * The weight of a signal, defaulting to zero so an unlisted signal simply
     * does not contribute rather than skewing the score.
     */
    public function weight(string $signal): float
    {
        return $this->weights[$signal] ?? 0.0;
    }
}
