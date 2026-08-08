<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\Request;

final class AnalyticsController extends Controller
{
    public function usage(Request $request)
    {
        // Prefer a dedicated AnalyticsService
        if (class_exists('App\\Services\\AnalyticsService')) {
            $svc = app('App\\Services\\AnalyticsService');
            if (method_exists($svc, 'usage')) {
                return ApiResponse::success($svc->usage($request->user()));
            }
        }

        // Try a UsageAnalytics helper/service if present
        if (class_exists('App\\Analytics\\UsageAnalytics') && method_exists(app('App\\Analytics\\UsageAnalytics'), 'summary')) {
            return ApiResponse::success(app('App\\Analytics\\UsageAnalytics')->summary($request->user()));
        }

        return ApiResponse::success([]);
    }

    public function performance(Request $request)
    {
        if (class_exists('App\\Services\\AnalyticsService')) {
            $svc = app('App\\Services\\AnalyticsService');
            if (method_exists($svc, 'performance')) {
                return ApiResponse::success($svc->performance($request->user()));
            }
        }

        if (class_exists('App\\Analytics\\PerformanceAnalytics') && method_exists(app('App\\Analytics\\PerformanceAnalytics'), 'summary')) {
            return ApiResponse::success(app('App\\Analytics\\PerformanceAnalytics')->summary($request->user()));
        }

        return ApiResponse::success([]);
    }
}
