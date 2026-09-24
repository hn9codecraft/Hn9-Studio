<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Contracts\Services\DashboardServiceInterface;
use App\Http\Controllers\Controller;
use App\Http\Requests\DashboardActionsRequest;
use App\Http\Requests\DashboardAnalyticsRequest;
use App\Http\Requests\DashboardUsageRequest;
use App\Http\Resources\DashboardActionsResource;
use App\Http\Resources\DashboardActivityResource;
use App\Http\Resources\DashboardAnalyticsResource;
use App\Http\Resources\DashboardCostsResource;
use App\Http\Resources\DashboardSummaryResource;
use App\Http\Resources\DashboardUsageResource;
use App\Models\Project;
use App\Support\ApiResponse;
use App\Support\PageSize;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class DashboardController extends Controller
{
    public function __construct(
        private DashboardServiceInterface $dashboard,
    ) {}

    public function summary(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Project::class);

        return ApiResponse::success(
            new DashboardSummaryResource($this->dashboard->summary($request->user())),
        );
    }

    public function activity(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Project::class);

        $perPage = PageSize::fromRequest($request, 20);
        $page = $this->dashboard->activity($request->user(), $perPage);

        return ApiResponse::success(DashboardActivityResource::collection($page->items()), 200, [
            'page' => $page->currentPage(),
            'perPage' => $page->perPage(),
            'total' => $page->total(),
            'lastPage' => $page->lastPage(),
        ]);
    }

    public function analytics(DashboardAnalyticsRequest $request): JsonResponse
    {
        $this->authorize('viewAny', Project::class);

        $from = $request->validated('from');
        $to = $request->validated('to');

        return ApiResponse::success(
            new DashboardAnalyticsResource(
                $this->dashboard->analytics($request->user(), $from, $to),
            ),
        );
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

    public function usage(DashboardUsageRequest $request): JsonResponse
    {
        $this->authorize('viewAny', Project::class);

        return ApiResponse::success(
            new DashboardUsageResource(
                $this->dashboard->usage($request->user(), $request->validated()),
            ),
        );
    }

    public function costs(DashboardUsageRequest $request): JsonResponse
    {
        $this->authorize('viewAny', Project::class);

        return ApiResponse::success(
            new DashboardCostsResource(
                $this->dashboard->costs($request->user(), $request->validated()),
            ),
        );
    }

    public function actions(DashboardActionsRequest $request): JsonResponse
    {
        $this->authorize('viewAny', Project::class);

        return ApiResponse::success(
            new DashboardActionsResource(
                $this->dashboard->actions($request->user(), $request->validated()),
            ),
        );
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
