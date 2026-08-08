<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Repositories\Contracts\ActivityLogRepositoryInterface;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ActivityLogController extends Controller
{
    public function __construct(
        private ActivityLogRepositoryInterface $activityLogs,
    ) {}

    public function index(Request $request): JsonResponse
    {
        // The audit trail spans every user and carries IP addresses and
        // before/after payloads, and the query below applies no ownership
        // scope — so listing it is gated to administrators.
        $this->authorize('viewAny', ActivityLog::class);

        $perPage = (int) $request->query('per_page', '15');

        $filters = [];
        foreach (['action', 'user_id'] as $key) {
            if ($request->has($key)) {
                $filters[$key] = $request->query($key);
            }
        }

        return ApiResponse::success($this->activityLogs->paginate($perPage, $filters));
    }
}
