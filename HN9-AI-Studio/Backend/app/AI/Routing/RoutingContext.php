<?php

declare(strict_types=1);

namespace App\AI\Routing;

use App\AI\Config\PlatformConfig;
use App\AI\Contracts\ProviderRequestInterface;
use App\AI\DTOs\ProviderRequestDTO;
use App\AI\Execution\DispatchOptions;
use App\AI\Support\Capability;
use App\AI\Support\CostStrategy;
use App\AI\Support\Modality;

/**
 * Everything routing needs to know about one dispatch, resolved once: the
 * capability being asked for, the model if the caller pinned one, the caller's
 * provider preference and exclusions, the cost preference and budget, and the
 * strategy to rank with.
 *
 * Assembling it here means the router never reads configuration or inspects a
 * request — it works from a single immutable input, which is also what makes it
 * trivially testable.
 */
final readonly class RoutingContext
{
    /**
     * @param  list<Capability>  $requiredCapabilities  Extra capabilities the provider must declare.
     * @param  list<string>  $preferred  Caller/operator preference, highest first.
     * @param  list<string>  $excluded  Provider keys the caller ruled out.
     */
    public function __construct(
        public Capability $capability,
        public Modality $modality,
        public string $strategy,
        public CostStrategy $costStrategy,
        public ?string $model = null,
        public array $requiredCapabilities = [],
        public array $preferred = [],
        public array $excluded = [],
        public ?float $budget = null,
        public bool $estimateCost = false,
        public ?ProviderRequestDTO $request = null,
    ) {}

    /**
     * Build the context for a request, merging caller options over configuration.
     */
    public static function for(
        ProviderRequestInterface $request,
        DispatchOptions $options,
        PlatformConfig $config,
    ): self {
        $costStrategy = $options->costStrategy ?? $config->cost->strategy;
        $budget = $config->cost->budgetFor($options->budget);

        return new self(
            capability: $request->modality()->capability(),
            modality: $request->modality(),
            // An explicit strategy wins; otherwise the cost preference names one.
            strategy: $options->strategy ?? $config->routing->strategy,
            costStrategy: $costStrategy,
            model: $options->model ?? $request->model(),
            requiredCapabilities: $options->requiredCapabilities,
            preferred: $options->preferredProviders !== []
                ? $options->preferredProviders
                : $config->routing->preferred,
            excluded: $options->excludedProviders,
            budget: $budget,
            estimateCost: $config->cost->estimatesFor($costStrategy, $options->budget),
            request: ProviderRequestDTO::fromRequest($request),
        );
    }

    /**
     * Every capability a provider must declare to be a candidate.
     *
     * @return list<Capability>
     */
    public function capabilities(): array
    {
        return array_values(array_unique([$this->capability, ...$this->requiredCapabilities], SORT_REGULAR));
    }

    /**
     * The caller's preference rank for a key, or null when unpreferred.
     */
    public function preferenceRank(string $key): ?int
    {
        $rank = array_search($key, $this->preferred, true);

        return $rank === false ? null : $rank;
    }

    public function excludes(string $key): bool
    {
        return in_array($key, $this->excluded, true);
    }
}
