<?php

declare(strict_types=1);

namespace App\AI\Health;

use App\AI\Contracts\HealthManagerInterface;
use App\AI\Contracts\ProviderFactoryInterface;
use App\AI\Contracts\ProviderRegistryInterface;
use App\AI\DTOs\ProviderHealthDTO;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Aggregates provider health. Resolves each enabled provider through the
 * factory and invokes its health probe, isolating failures so one unhealthy
 * (or throwing) provider never breaks the aggregate.
 *
 * With no providers registered this sprint, the aggregate is simply empty —
 * the harness is in place for the probes that ship with the providers.
 */
final readonly class HealthManager implements HealthManagerInterface
{
    public function __construct(
        private ProviderRegistryInterface $registry,
        private ProviderFactoryInterface $factory,
    ) {}

    public function check(string $key): ProviderHealthDTO
    {
        $now = Carbon::now()->toIso8601String();

        try {
            $provider = $this->factory->make($key);
            $startedAt = microtime(true);
            $health = $provider->healthCheck();
            $measured = (int) round((microtime(true) - $startedAt) * 1000);

            return new ProviderHealthDTO(
                key: $key,
                status: $health->status,
                latencyMs: $health->latencyMs ?? $measured,
                message: $health->message,
                checkedAt: $health->checkedAt ?? $now,
                details: $health->details,
            );
        } catch (Throwable $e) {
            return ProviderHealthDTO::unavailable($key, $e->getMessage(), $now);
        }
    }

    public function aggregate(): array
    {
        $report = [];

        foreach (array_keys($this->registry->enabled()) as $key) {
            $report[$key] = $this->check($key);
        }

        return $report;
    }
}
