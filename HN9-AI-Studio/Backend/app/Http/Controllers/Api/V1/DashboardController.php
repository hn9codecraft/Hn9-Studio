<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\Request;

final class DashboardController extends Controller
{
    public function summary(Request $request)
    {
        $payload = [];

        // Prefer an explicit DashboardService when available.
        if (class_exists('App\\Services\\DashboardService')) {
            $svc = app('App\\Services\\DashboardService');
            if (method_exists($svc, 'summary')) {
                $payload = $svc->summary($request->user());

                return ApiResponse::success($payload);
            }
        }

        // Fallback: assemble pieces from known services if present.
        // ProjectService has no dashboard projection, so there is nothing to
        // read here until a DashboardService exists.
        $payload['projects'] = [];
        $payload['usage'] = class_exists('App\\Services\\UsageService') ? app('App\\Services\\UsageService')->summary($request->user()) : [];
        $payload['costs'] = class_exists('App\\Services\\CostService') ? app('App\\Services\\CostService')->summary($request->user()) : [];
        $payload['notifications'] = class_exists('App\\Services\\NotificationService') ? app('App\\Services\\NotificationService')->recentForUser($request->user()) : [];

        return ApiResponse::success($payload);
    }

    public function projects(Request $request)
    {
        if (class_exists('App\\Services\\DashboardService')) {
            $svc = app('App\\Services\\DashboardService');
            if (method_exists($svc, 'projects')) {
                return ApiResponse::success($svc->projects($request->user()));
            }
        }

        return ApiResponse::success([]);
    }

    public function usage(Request $request)
    {
        if (class_exists('App\\Services\\UsageService')) {
            $svc = app('App\\Services\\UsageService');
            if (method_exists($svc, 'summary')) {
                return ApiResponse::success($svc->summary($request->user()));
            }
        }

        return ApiResponse::success([]);
    }

    public function costs(Request $request)
    {
        if (class_exists('App\\Services\\CostService')) {
            $svc = app('App\\Services\\CostService');
            if (method_exists($svc, 'summary')) {
                return ApiResponse::success($svc->summary($request->user()));
            }
        }

        return ApiResponse::success([]);
    }

    public function notifications(Request $request)
    {
        if (class_exists('App\\Services\\NotificationService')) {
            $svc = app('App\\Services\\NotificationService');
            if (method_exists($svc, 'recentForUser')) {
                return ApiResponse::success($svc->recentForUser($request->user()));
            }
        }

        return ApiResponse::success([]);
    }
}
