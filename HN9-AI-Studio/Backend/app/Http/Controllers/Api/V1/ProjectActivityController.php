<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Contracts\Services\ProjectActivityServiceInterface;
use App\Contracts\Services\ProjectServiceInterface;
use App\Http\Controllers\Controller;
use App\Http\Requests\IndexProjectActivityRequest;
use App\Http\Resources\ProjectActivityResource;
use App\Support\ApiResponse;
use App\Support\PageSize;
use Illuminate\Http\JsonResponse;

/**
 * Read-only nested activity timeline for a project. There is no store/update
 * route — activity is written by real studio services via ActivityLogger.
 */
class ProjectActivityController extends Controller
{
    public function __construct(
        private ProjectServiceInterface $projects,
        private ProjectActivityServiceInterface $activities,
    ) {}

    public function index(IndexProjectActivityRequest $request, string $uuid): JsonResponse
    {
        $project = $this->projects->getByUuid($uuid);

        $this->authorize('view', $project);

        $perPage = PageSize::fromRequest($request, 50);
        $filters = $request->safe()->only(['module', 'action', 'order']);
        $page = $this->activities->paginateForProject($project, $perPage, $filters);

        return ApiResponse::success(ProjectActivityResource::collection($page->items()), 200, [
            'page' => $page->currentPage(),
            'perPage' => $page->perPage(),
            'total' => $page->total(),
            'lastPage' => $page->lastPage(),
        ]);
    }
}
