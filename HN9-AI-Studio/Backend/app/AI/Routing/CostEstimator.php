<?php

declare(strict_types=1);

namespace App\AI\Routing;

use App\AI\Cache\ProviderInstanceCache;
use App\AI\DTOs\ProviderRequestDTO;
use Throwable;

/**
 * Estimates what a request would cost with a given provider, before it is sent.
 *
 * Estimation delegates to each provider's own `estimateCost()` — the pricing
 * knowledge stays with the adapter that owns it, and nothing is duplicated
 * here. The value this adds is memoisation and containment:
 *
 *  - identical (provider, request) pairs are computed once per process, which
 *    matters because routing may price every candidate;
 *  - a failing estimator yields null rather than an exception, since a request
 *    must never fail because its *price* could not be worked out. One adapter
 *    consults a remote tokenizer, so this path can touch the network — which is
 *    why cost optimisation is opt-in.
 */
final class CostEstimator
{
    /**
     * @var array<string, float|null>
     */
    private array $estimates = [];

    public function __construct(private readonly ProviderInstanceCache $providers) {}

    /**
     * The estimated cost, or null when it could not be determined.
     */
    public function estimate(string $providerKey, ProviderRequestDTO $request): ?float
    {
        $cacheKey = $this->cacheKey($providerKey, $request);

        if (array_key_exists($cacheKey, $this->estimates)) {
            return $this->estimates[$cacheKey];
        }

        try {
            $estimate = $this->providers->get($providerKey)->estimateCost($request);
        } catch (Throwable) {
            // Unpriced, unroutable or unreachable: the router treats it as unknown.
            return $this->estimates[$cacheKey] = null;
        }

        return $this->estimates[$cacheKey] = max(0.0, $estimate);
    }

    public function flush(): void
    {
        $this->estimates = [];
    }

    private function cacheKey(string $providerKey, ProviderRequestDTO $request): string
    {
        $payload = json_encode($request->toArray());

        return $providerKey.':'.md5($payload === false ? $providerKey : $payload);
    }
}
