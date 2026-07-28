<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\Services\HealthServiceInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Runs the application's health probes and assembles the status envelope. This
 * encapsulates the checks previously inlined in the health controller, keeping
 * the controller thin.
 */
final class HealthService implements HealthServiceInterface
{
    public function check(): array
    {
        $services = [
            'database' => $this->checkDatabase(),
        ];

        $healthy = ! in_array(false, $services, true);

        return [
            'status' => $healthy ? 'ok' : 'degraded',
            'healthy' => $healthy,
            'version' => (string) config('app.version'),
            'environment' => (string) config('app.env'),
            'timestamp' => Carbon::now()->toIso8601String(),
            'services' => $services,
        ];
    }

    /**
     * Lightweight database connectivity probe.
     */
    private function checkDatabase(): bool
    {
        try {
            DB::connection()->getPdo();

            return true;
        } catch (\Throwable) {
            return false;
        }
    }
}
