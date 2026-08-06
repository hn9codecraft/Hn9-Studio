<?php

declare(strict_types=1);

namespace App\AI\Routing;

use App\AI\Cache\ProviderMetadataCache;
use App\AI\Config\PlatformConfig;
use App\AI\Contracts\CircuitBreakerInterface;
use App\AI\Contracts\HealthManagerInterface;
use App\AI\Contracts\HealthTrackerInterface;
use App\AI\Contracts\MetricsCollectorInterface;
use App\AI\Contracts\ProviderRegistryInterface;
use App\AI\Contracts\ProviderRouterInterface;
use App\AI\Exceptions\AIException;
use App\AI\Exceptions\BudgetExceededException;
use App\AI\Exceptions\CircuitOpenException;
use App\AI\Exceptions\NoProviderAvailableException;
use App\AI\Support\Capability;
use App\AI\Support\HealthStatus;

/**
 * Builds the ordered plan of providers for a dispatch.
 *
 * Selection runs as a pipeline, each stage narrowing or informing the next:
 *
 *   1. capability   — the registry's own capability index supplies the pool;
 *   2. exclusions   — caller exclusions and the configured chain in restrict mode;
 *   3. model        — when a model is pinned, providers that do not publish it;
 *   4. circuit      — an open circuit removes a provider outright;
 *   5. health       — Unavailable is removed, Degraded is penalised, Unknown routes;
 *   6. cost         — estimates are attached and the budget filters, when enabled;
 *   7. scoring      — the active strategy ranks whatever survived;
 *   8. ordering     — preference first, then a pinned chain, then score.
 *
 * No provider key and no vendor behaviour appears anywhere in this class: every
 * key it handles arrives from the registry, from configuration or from the
 * caller. Adding a provider changes nothing here, and neither does adding a
 * capability — {@see Capability} drives the pool, so future modalities route
 * through the same path.
 */
final readonly class ProviderRouter implements ProviderRouterInterface
{
    /**
     * Sorts anything unranked (unpreferred, unchained) behind everything ranked.
     */
    private const UNRANKED = PHP_INT_MAX;

    /**
     * Rejection reason for a provider withheld by its circuit breaker.
     */
    private const REJECTED_CIRCUIT_OPEN = 'circuit_open';

    public function __construct(
        private ProviderRegistryInterface $registry,
        private ProviderMetadataCache $metadata,
        private RoutingStrategyRegistry $strategies,
        private CircuitBreakerInterface $breaker,
        private HealthTrackerInterface $healthTracker,
        private HealthManagerInterface $health,
        private MetricsCollectorInterface $metrics,
        private CostEstimator $costs,
        private PlatformConfig $config,
    ) {}

    public function route(RoutingContext $context): RoutingPlan
    {
        $rejected = [];
        $candidates = [];
        $chain = $this->config->routing->fallback->chainFor($context->capability);

        foreach ($this->registry->forCapability($context->capability) as $key) {
            $rejection = $this->reject($key, $context, $chain);

            if ($rejection !== null) {
                $rejected[$key] = $rejection;

                continue;
            }

            $candidates[] = $this->candidate($key, $context, $chain);
        }

        $candidates = $this->withinBudget($candidates, $context, $rejected);

        if ($candidates === []) {
            throw $this->emptyPlanFailure($context, $rejected);
        }

        return new RoutingPlan($this->rank($candidates, $context), $context, $rejected);
    }

    /**
     * The failure that best explains an empty plan.
     *
     * "Everything is breaking" and "nothing can serve this" call for different
     * responses — the first is transient and worth retrying, the second needs
     * an operator — so they are reported as different exceptions.
     *
     * @param  array<string, string>  $rejected
     */
    private function emptyPlanFailure(RoutingContext $context, array $rejected): AIException
    {
        $breaking = array_keys($rejected, self::REJECTED_CIRCUIT_OPEN, true);

        if ($rejected !== [] && count($breaking) === count($rejected)) {
            return CircuitOpenException::forProviders(array_combine(
                $breaking,
                array_map(fn (string $key): int => $this->breaker->retryAfter($key), $breaking),
            ));
        }

        return NoProviderAvailableException::forCapability($context->capability, $rejected);
    }

    /**
     * Why a provider cannot serve this request, or null when it can.
     *
     * @param  list<string>  $chain
     */
    private function reject(string $key, RoutingContext $context, array $chain): ?string
    {
        if ($context->excludes($key)) {
            return 'excluded_by_caller';
        }

        if ($chain !== [] && $this->config->routing->fallback->restricts() && ! in_array($key, $chain, true)) {
            return 'outside_fallback_chain';
        }

        foreach ($context->capabilities() as $capability) {
            if (! $this->metadata->supports($key, $capability)) {
                return 'missing_capability:'.$capability->value;
            }
        }

        if ($context->model !== null && ! $this->metadata->exposesModel($key, $context->model)) {
            return 'model_not_configured';
        }

        if (! $this->breaker->allows($key)) {
            return self::REJECTED_CIRCUIT_OPEN;
        }

        $health = $this->healthOf($key);

        if ($this->config->routing->health->enabled && ! $this->config->routing->health->admits($health)) {
            return 'health:'.$health->value;
        }

        return null;
    }

    /**
     * @param  list<string>  $chain
     */
    private function candidate(string $key, RoutingContext $context, array $chain): ProviderCandidate
    {
        $metrics = $this->metrics->forProvider($key);
        $chainRank = array_search($key, $chain, true);

        return new ProviderCandidate(
            key: $key,
            priority: $this->registry->get($key)->priority(),
            health: $this->healthOf($key),
            circuit: $this->breaker->state($key),
            estimatedCost: $context->estimateCost && $context->request !== null
                ? $this->costs->estimate($key, $context->request)
                : null,
            averageLatencyMs: $metrics->requests > 0 ? $metrics->averageResponseMs() : null,
            reliability: $metrics->reliability(),
            chainRank: $chainRank === false ? null : $chainRank,
            preferenceRank: $context->preferenceRank($key),
        );
    }

    /**
     * The provider's effective health: the worse of what has been observed and,
     * when probing is enabled, what the last probe reported.
     */
    private function healthOf(string $key): HealthStatus
    {
        $observed = $this->healthTracker->status($key);

        if (! $this->config->routing->health->probe) {
            return $observed;
        }

        return $this->worse($observed, $this->health->check($key)->status);
    }

    private function worse(HealthStatus $first, HealthStatus $second): HealthStatus
    {
        $severity = [
            HealthStatus::Healthy->value => 0,
            HealthStatus::Unknown->value => 1,
            HealthStatus::Degraded->value => 2,
            HealthStatus::Unavailable->value => 3,
        ];

        return $severity[$first->value] >= $severity[$second->value] ? $first : $second;
    }

    /**
     * Drop candidates whose estimate exceeds the budget. When the budget rules
     * out everyone, that is a distinct failure from "nothing can serve this".
     *
     * @param  list<ProviderCandidate>  $candidates
     * @param  array<string, string>  $rejected
     * @return list<ProviderCandidate>
     */
    private function withinBudget(array $candidates, RoutingContext $context, array &$rejected): array
    {
        $budget = $context->budget;

        if ($budget === null || $candidates === []) {
            return $candidates;
        }

        $affordable = [];
        $estimates = [];

        foreach ($candidates as $candidate) {
            if ($candidate->estimatedCost !== null && $candidate->estimatedCost > $budget) {
                $estimates[$candidate->key] = $candidate->estimatedCost;
                $rejected[$candidate->key] = 'over_budget';

                continue;
            }

            $affordable[] = $candidate;
        }

        if ($affordable === [] && $estimates !== []) {
            throw BudgetExceededException::forCapability(
                $context->capability,
                $budget,
                $estimates,
                $this->config->cost->currency,
            );
        }

        return $affordable;
    }

    /**
     * Score every candidate and order them best first.
     *
     * Ordering is lexicographic rather than one summed number, because the
     * inputs are not commensurable: a caller's explicit preference is not
     * "worth" some quantity of price or latency. Each rule is applied in turn
     * and only breaks the ties the rule above it left:
     *
     *   1. caller preference   — an explicit ask wins outright;
     *   2. health              — degraded ranks behind healthy peers;
     *   3. fallback chain      — operator-pinned order, in `order` mode;
     *   4. strategy score      — the measured ranking;
     *   5. priority, then key  — a stable, reproducible final order.
     *
     * @param  list<ProviderCandidate>  $candidates
     * @return list<ProviderCandidate>
     */
    private function rank(array $candidates, RoutingContext $context): array
    {
        $strategy = $this->strategies->get($context->strategy);
        $scale = CandidateScale::of($candidates);
        $health = $this->config->routing->health;
        $ordersByChain = ! $this->config->routing->fallback->restricts();

        $scored = array_map(
            static fn (ProviderCandidate $candidate): ProviderCandidate => $candidate->scored(
                $strategy->score($candidate, $scale, $context),
            ),
            $candidates,
        );

        usort($scored, static function (ProviderCandidate $a, ProviderCandidate $b) use ($health, $ordersByChain): int {
            $chainOf = static fn (ProviderCandidate $c): int => $ordersByChain
                ? $c->chainRank ?? self::UNRANKED
                : 0;

            return ($a->preferenceRank ?? self::UNRANKED) <=> ($b->preferenceRank ?? self::UNRANKED)
                ?: $health->tierFor($a->health) <=> $health->tierFor($b->health)
                ?: $chainOf($a) <=> $chainOf($b)
                ?: $b->score <=> $a->score
                ?: $b->priority <=> $a->priority
                ?: strcmp($a->key, $b->key);
        });

        return $scored;
    }
}
