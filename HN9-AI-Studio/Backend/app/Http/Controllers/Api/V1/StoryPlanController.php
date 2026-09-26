<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Contracts\Services\ProjectServiceInterface;
use App\Http\Controllers\Controller;
use App\Http\Requests\GenerateStoryPlanRequest;
use App\Http\Requests\RegenerateStoryPlanRequest;
use App\Http\Requests\StoreStoryPlanRequest;
use App\Http\Resources\StoryPlanResource;
use App\Http\Resources\StoryPlanVersionResource;
use App\Story\Contracts\StoryPlannerServiceInterface;
use App\Story\Models\StoryWorkspace;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

class StoryPlanController extends Controller
{
    public function __construct(
        private StoryPlannerServiceInterface $planner,
        private ProjectServiceInterface $projects,
    ) {}

    public function index(string $uuid): JsonResponse
    {
        $project = $this->projects->getByUuid($uuid);
        $this->authorize('select', [StoryWorkspace::class, $project]);

        $items = $this->planner->listForProject($project);

        return ApiResponse::success(StoryPlanResource::collection($items));
    }

    public function store(StoreStoryPlanRequest $request, string $uuid): JsonResponse
    {
        $project = $this->projects->getByUuid($uuid);
        $this->authorize('select', [StoryWorkspace::class, $project]);

        $plan = $this->planner->create($project, $request->validated());
        $this->authorize('view', $plan);

        return ApiResponse::created(new StoryPlanResource($plan));
    }

    public function show(string $uuid, string $planUuid): JsonResponse
    {
        $project = $this->projects->getByUuid($uuid);
        $this->authorize('select', [StoryWorkspace::class, $project]);

        $plan = $this->planner->getForProject($project, $planUuid);
        $this->authorize('view', $plan);

        return ApiResponse::success(new StoryPlanResource($plan));
    }

    public function generate(GenerateStoryPlanRequest $request, string $uuid, string $planUuid): JsonResponse
    {
        $project = $this->projects->getByUuid($uuid);
        $this->authorize('select', [StoryWorkspace::class, $project]);

        $plan = $this->planner->getForProject($project, $planUuid);
        $this->authorize('generate', $plan);

        $generated = $this->planner->generate($project, $planUuid, $request->validated());

        return ApiResponse::success(new StoryPlanResource($generated));
    }

    public function regenerate(RegenerateStoryPlanRequest $request, string $uuid, string $planUuid): JsonResponse
    {
        $project = $this->projects->getByUuid($uuid);
        $this->authorize('select', [StoryWorkspace::class, $project]);

        $plan = $this->planner->getForProject($project, $planUuid);
        $this->authorize('generate', $plan);

        $validated = $request->validated();
        $instruction = is_string($validated['instruction'] ?? null) ? $validated['instruction'] : null;
        unset($validated['instruction']);

        $generated = $this->planner->regenerate($project, $planUuid, $instruction, $validated);

        return ApiResponse::success(new StoryPlanResource($generated));
    }

    public function versions(string $uuid, string $planUuid): JsonResponse
    {
        $project = $this->projects->getByUuid($uuid);
        $this->authorize('select', [StoryWorkspace::class, $project]);

        $plan = $this->planner->getForProject($project, $planUuid);
        $this->authorize('view', $plan);

        $items = $this->planner->versionsForProject($project, $planUuid);

        return ApiResponse::success(StoryPlanVersionResource::collection($items));
    }
}
