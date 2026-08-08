<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Contracts\Services\ProjectServiceInterface;
use App\DTOs\Project\CreateProjectData;
use App\DTOs\Project\UpdateProjectData;
use App\Enums\ProjectStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreProjectRequest;
use App\Http\Requests\UpdateProjectRequest;
use App\Http\Resources\ProjectResource;
use App\Models\Project;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProjectController extends Controller
{
    public function __construct(private ProjectServiceInterface $projects) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Project::class);

        $perPage = (int) ($request->query('perPage', 15));

        $filters = $request->only(['status', 'type', 'search', 'sort', 'order', 'date', 'owner', 'created_by']);

        $page = $this->projects->paginateForUser($request->user(), $perPage, $filters);

        return ApiResponse::success(ProjectResource::collection($page->items()), 200, [
            'page' => $page->currentPage(),
            'perPage' => $page->perPage(),
            'total' => $page->total(),
            'lastPage' => $page->lastPage(),
        ]);
    }

    public function store(StoreProjectRequest $request): JsonResponse
    {
        $this->authorize('create', Project::class);

        $data = CreateProjectData::fromArray(array_merge($request->validated(), ['user_id' => $request->user()->getKey()]));

        $project = $this->projects->create($data, $request->user());

        return ApiResponse::created(new ProjectResource($project));
    }

    public function show(string $uuid): JsonResponse
    {
        $project = $this->projects->getByUuid($uuid);

        $this->authorize('view', $project);

        return ApiResponse::success(new ProjectResource($project));
    }

    public function update(UpdateProjectRequest $request, string $uuid): JsonResponse
    {
        $project = $this->projects->getByUuid($uuid);

        $this->authorize('update', $project);

        $dto = UpdateProjectData::fromArray($request->validated());

        $updated = $this->projects->update($project, $dto, $request->user());

        return ApiResponse::success(new ProjectResource($updated));
    }

    public function destroy(string $uuid): JsonResponse
    {
        $project = $this->projects->getByUuid($uuid);

        $this->authorize('delete', $project);

        $this->projects->delete($project, request()->user());

        return ApiResponse::noContent();
    }

    public function archive(string $uuid): JsonResponse
    {
        $project = $this->projects->getByUuid($uuid);

        $this->authorize('update', $project);

        $updated = $this->projects->changeStatus($project, ProjectStatus::Archived, request()->user());

        return ApiResponse::success(new ProjectResource($updated));
    }

    public function restore(string $uuid): JsonResponse
    {
        $project = $this->projects->getByUuidWithTrashed($uuid);

        $this->authorize('restore', $project);

        $restored = $this->projects->restore($uuid);

        return ApiResponse::success(new ProjectResource($restored));
    }
}
