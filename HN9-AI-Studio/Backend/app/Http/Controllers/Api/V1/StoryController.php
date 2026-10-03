<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Contracts\Services\ProjectServiceInterface;
use App\Http\Controllers\Controller;
use App\Http\Resources\ProjectResource;
use App\Http\Resources\StoryWorkspaceResource;
use App\Story\Contracts\StoryWorkspaceServiceInterface;
use App\Story\Models\StoryWorkspace;
use App\Story\Services\StoryReadinessService;
use App\Story\Video\StoryCapabilityRoute;
use App\Support\ApiResponse;
use App\Support\PageSize;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StoryController extends Controller
{
    public function __construct(
        private StoryWorkspaceServiceInterface $stories,
        private ProjectServiceInterface $projects,
    ) {}

    public function entry(): JsonResponse
    {
        $this->authorize('viewAny', StoryWorkspace::class);

        return ApiResponse::success([
            'module' => 'project_story',
            'title' => 'Creative Production Studio',
            'description' => 'Plan, produce and finish story, image, video and audio content inside an existing project.',
            'capabilities' => array_map(
                static fn (StoryCapabilityRoute $route): array => $route->toArray(),
                $this->stories->capabilityCatalog(),
            ),
        ]);
    }

    public function projects(Request $request): JsonResponse
    {
        $this->authorize('viewAny', StoryWorkspace::class);

        $perPage = PageSize::fromRequest($request, 50);
        $filters = $request->only(['status', 'type', 'search', 'sort', 'order']);
        $page = $this->stories->selectableProjects($request->user(), $perPage, $filters);

        return ApiResponse::success(ProjectResource::collection($page->items()), 200, [
            'page' => $page->currentPage(),
            'perPage' => $page->perPage(),
            'total' => $page->total(),
            'lastPage' => $page->lastPage(),
        ]);
    }

    public function show(Request $request, string $uuid, StoryReadinessService $readiness): JsonResponse
    {
        $project = $this->projects->getByUuid($uuid);

        $this->authorize('select', [StoryWorkspace::class, $project]);

        $workspace = $this->stories->workspaceForProject($project);

        $this->authorize('view', $workspace);

        return ApiResponse::success([
            ...(new StoryWorkspaceResource($workspace))->toArray($request),
            'connections' => $readiness->connections(),
        ]);
    }

    public function capabilities(): JsonResponse
    {
        $this->authorize('viewAny', StoryWorkspace::class);

        return ApiResponse::success(array_map(
            static fn (StoryCapabilityRoute $route): array => $route->toArray(),
            $this->stories->capabilityCatalog(),
        ));
    }
}
