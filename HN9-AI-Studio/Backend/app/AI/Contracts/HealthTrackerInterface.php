<?php

declare(strict_types=1);

namespace App\AI\Contracts;

use App\AI\DTOs\ProviderHealthDTO;
use App\AI\Support\HealthStatus;
use Throwable;

/**
 * Passive health: the status the platform infers from the calls it has already
 * made, as opposed to the active probes behind {@see HealthManagerInterface}.
 *
 * Observed state carries a recovery TTL, so a provider that stops failing —
 * even one receiving no traffic at all — returns to Unknown and to the routing
 * table without intervention.
 */
interface HealthTrackerInterface
{
    /**
     * Note a successful call, clearing any recorded failure run.
     */
    public function recordSuccess(string $provider, int $latencyMs): void;

    /**
     * Note a failed call, advancing the provider towards degraded/unavailable.
     */
    public function recordFailure(string $provider, Throwable $failure): void;

    /**
     * The inferred status, or Unknown when nothing has been observed.
     */
    public function status(string $provider): HealthStatus;

    /**
     * The observed status as a health DTO, for reporting alongside probes.
     */
    public function snapshot(string $provider): ProviderHealthDTO;

    /**
     * Forget everything observed about a provider.
     */
    public function forget(string $provider): void;
}
