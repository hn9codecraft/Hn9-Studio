<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Contracts\Services\HealthServiceInterface;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

/**
 * Reports the liveness of the API and its critical backing services.
 *
 * The controller is thin: it delegates the probes to the health service and
 * only maps the result to an HTTP status/envelope. It contains no logic of
 * its own.
 */
class HealthController extends Controller
{
    public function __construct(private readonly HealthServiceInterface $health) {}

    /**
     * GET /api/v1/health
     */
    public function __invoke(): JsonResponse
    {
        $result = $this->health->check();

        return response()->json([
            'status' => $result['status'],
            'version' => $result['version'],
            'environment' => $result['environment'],
            'timestamp' => $result['timestamp'],
            'services' => $result['services'],
        ], $result['healthy'] ? 200 : 503);
    }
}
