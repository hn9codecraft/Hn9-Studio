<?php

declare(strict_types=1);

namespace App\Contracts\Services;

/**
 * Reports the liveness/health of the application and its backing services.
 * Encapsulates the probes previously inlined in the health controller so the
 * controller only presents the result.
 */
interface HealthServiceInterface
{
    /**
     * Run all health probes and return a status envelope.
     *
     * @return array{status: string, healthy: bool, version: string, environment: string, timestamp: string, services: array<string, bool>}
     */
    public function check(): array;
}
