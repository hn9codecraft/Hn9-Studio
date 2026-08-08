<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Contracts\Services\ProjectServiceInterface;
use App\Http\Controllers\Controller;
use App\Http\Requests\IndexProjectPromptRequest;
use App\Http\Requests\StoreProjectInputRequest;
use App\Http\Resources\ProjectInputResource;
use App\Repositories\Contracts\ProjectInputRepositoryInterface;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

final class ProjectPromptController extends Controller
{
    public function __construct(
        private ProjectServiceInterface $projects,
        private ProjectInputRepositoryInterface $inputs,
    ) {}

    public function index(IndexProjectPromptRequest $request, string $projectUuid): JsonResponse
    {
        $project = $this->projects->getByUuid($projectUuid);

        $this->authorize('view', $project);

        $items = $this->inputs->forProject($project->getKey())
            ->where('type', 'prompt');

        return ApiResponse::success(ProjectInputResource::collection($items));
    }

    public function store(StoreProjectInputRequest $request, string $projectUuid): JsonResponse
    {
        $project = $this->projects->getByUuid($projectUuid);

        $this->authorize('update', $project);

        $payload = $request->validated();
        $payload['project_id'] = $project->getKey();
        $payload['user_id'] = $request->user()?->getKey();
        $payload['type'] = 'prompt';

        $created = $this->inputs->create($payload);

        return ApiResponse::created(new ProjectInputResource($created));
    }

    public function show(string $projectUuid, string $promptUuid): JsonResponse
    {
        $project = $this->projects->getByUuid($projectUuid);

        $this->authorize('view', $project);

        $item = $this->inputs->findByUuidOrFail($promptUuid);

        return ApiResponse::success(new ProjectInputResource($item));
    }

    public function destroy(string $projectUuid, string $promptUuid): JsonResponse
    {
        $project = $this->projects->getByUuid($projectUuid);

        $this->authorize('update', $project);

        $item = $this->inputs->findByUuidOrFail($promptUuid);

        $this->inputs->delete($item);

        return ApiResponse::noContent();
    }
}
