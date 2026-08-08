<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\Request;

final class TraceController extends Controller
{
    public function index(Request $request)
    {
        // Prefer a repository if provided by the application. This keeps the
        // controller thin and defers query logic to the repo/service layers.
        $traceRepoInterface = 'App\\Repositories\\Contracts\\TraceRepositoryInterface';
        $traceServiceClass = 'App\\Services\\TraceService';

        if (app()->bound($traceRepoInterface)) {
            $repo = app($traceRepoInterface);

            $perPage = (int) $request->query('per_page', 15);
            $filters = [];
            // Allow basic filtering if the repository supports filterable columns.
            foreach (['level', 'source', 'user_id'] as $key) {
                if ($request->has($key)) {
                    $filters[$key] = $request->query($key);
                }
            }

            $page = $repo->paginate($perPage, $filters);

            return ApiResponse::success($page);
        }

        if (class_exists($traceServiceClass) && app()->bound($traceServiceClass)) {
            $service = app($traceServiceClass);
            if (method_exists($service, 'paginate')) {
                $perPage = (int) $request->query('per_page', 15);
                $page = $service->paginate($perPage, $request->all());

                return ApiResponse::success($page);
            }
        }

        // No tracing infra present — return an empty paginated envelope.
        return ApiResponse::success(['data' => [], 'meta' => ['total' => 0]]);
    }
}
