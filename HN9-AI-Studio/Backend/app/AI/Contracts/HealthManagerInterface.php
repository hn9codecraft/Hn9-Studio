<?php

declare(strict_types=1);

namespace App\AI\Contracts;

use App\AI\DTOs\ProviderHealthDTO;

/**
 * Aggregates health across registered providers. Resolves each provider
 * through the factory and invokes its health probe, isolating failures so one
 * unhealthy provider never breaks the aggregate report.
 */
interface HealthManagerInterface
{
    /**
     * Probe a single provider by key.
     */
    public function check(string $key): ProviderHealthDTO;

    /**
     * Probe every enabled provider.
     *
     * @return array<string, ProviderHealthDTO>
     */
    public function aggregate(): array;
}
