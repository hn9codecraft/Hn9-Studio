<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\Request;

final class SystemController extends Controller
{
    public function metrics(Request $request)
    {
        // Prefer a dedicated SystemService
        if (class_exists('App\\Services\\SystemService')) {
            $svc = app('App\\Services\\SystemService');
            if (method_exists($svc, 'metrics')) {
                return ApiResponse::success($svc->metrics());
            }
        }

        // Try a MetricsService fallback
        if (class_exists('App\\Services\\MetricsService')) {
            $svc = app('App\\Services\\MetricsService');
            if (method_exists($svc, 'collect')) {
                return ApiResponse::success($svc->collect());
            }
            if (method_exists($svc, 'metrics')) {
                return ApiResponse::success($svc->metrics());
            }
        }

        // No service found: return an empty metrics envelope to remain non-invasive.
        return ApiResponse::success(['metrics' => []]);
    }
}
