<?php

declare(strict_types=1);

namespace App\AI\Execution;

use App\AI\Support\Capability;
use App\AI\Support\CostStrategy;

/**
 * Per-request overrides for a dispatch. Every field is optional: an untouched
 * option object dispatches exactly as configuration says.
 *
 * The fluent `with*` methods return copies, so an option set can be shared and
 * specialised without a caller mutating another's.
 */
final readonly class DispatchOptions
{
    /**
     * @param  list<string>  $preferredProviders  Tried first, highest first.
     * @param  list<string>  $excludedProviders  Never tried.
     * @param  list<Capability>  $requiredCapabilities  Beyond the request's own modality.
     */
    public function __construct(
        public array $preferredProviders = [],
        public array $excludedProviders = [],
        public array $requiredCapabilities = [],
        public ?string $strategy = null,
        public ?CostStrategy $costStrategy = null,
        public ?float $budget = null,
        public ?string $model = null,
        public ?int $maxAttempts = null,
        public ?int $maxProviders = null,
        public ?int $timeoutMs = null,
    ) {}

    public static function make(): self
    {
        return new self;
    }

    /**
     * Pin a single provider: prefer it, and try nothing else.
     */
    public static function only(string $provider): self
    {
        return new self(preferredProviders: [$provider], maxProviders: 1);
    }

    public function withPreferred(string ...$providers): self
    {
        return $this->copy(preferredProviders: array_values($providers));
    }

    public function without(string ...$providers): self
    {
        return $this->copy(excludedProviders: array_values($providers));
    }

    public function withStrategy(string $strategy): self
    {
        return $this->copy(strategy: $strategy);
    }

    public function withCostStrategy(CostStrategy $strategy): self
    {
        return $this->copy(costStrategy: $strategy);
    }

    public function withBudget(float $budget): self
    {
        return $this->copy(budget: $budget);
    }

    public function withTimeout(int $milliseconds): self
    {
        return $this->copy(timeoutMs: $milliseconds);
    }

    public function withMaxAttempts(int $attempts): self
    {
        return $this->copy(maxAttempts: $attempts);
    }

    public function withMaxProviders(int $providers): self
    {
        return $this->copy(maxProviders: $providers);
    }

    /**
     * @param  list<string>|null  $preferredProviders
     * @param  list<string>|null  $excludedProviders
     * @param  list<Capability>|null  $requiredCapabilities
     */
    private function copy(
        ?array $preferredProviders = null,
        ?array $excludedProviders = null,
        ?array $requiredCapabilities = null,
        ?string $strategy = null,
        ?CostStrategy $costStrategy = null,
        ?float $budget = null,
        ?string $model = null,
        ?int $maxAttempts = null,
        ?int $maxProviders = null,
        ?int $timeoutMs = null,
    ): self {
        return new self(
            preferredProviders: $preferredProviders ?? $this->preferredProviders,
            excludedProviders: $excludedProviders ?? $this->excludedProviders,
            requiredCapabilities: $requiredCapabilities ?? $this->requiredCapabilities,
            strategy: $strategy ?? $this->strategy,
            costStrategy: $costStrategy ?? $this->costStrategy,
            budget: $budget ?? $this->budget,
            model: $model ?? $this->model,
            maxAttempts: $maxAttempts ?? $this->maxAttempts,
            maxProviders: $maxProviders ?? $this->maxProviders,
            timeoutMs: $timeoutMs ?? $this->timeoutMs,
        );
    }
}
