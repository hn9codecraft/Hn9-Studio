<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Contracts\Services\ProjectServiceInterface;
use App\Http\Controllers\Controller;
use App\Http\Resources\StoryProductionPlanResource;
use App\Http\Resources\StoryProductionPlanSceneResource;
use App\Story\Contracts\StoryProductionPlanServiceInterface;
use App\Story\Models\StoryWorkspace;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

/**
 * Read-only access to production plans. Plans are created and revised by the production workflow.
 */
class StoryProductionPlanController extends Controller
{
    public function __construct(
        private StoryProductionPlanServiceInterface $plans,
        private ProjectServiceInterface $projects,
    ) {}

    public function index(string $uuid): JsonResponse
    {
        $project = $this->projects->getByUuid($uuid);
        $this->authorize('select', [StoryWorkspace::class, $project]);

        return ApiResponse::success(StoryProductionPlanResource::collection($this->plans->listForProject($project)));
    }

    public function show(string $uuid, string $planUuid): JsonResponse
    {
        $project = $this->projects->getByUuid($uuid);
        $this->authorize('select', [StoryWorkspace::class, $project]);

        $plan = $this->plans->getForProject($project, $planUuid);
        $this->authorize('view', $plan);

        return ApiResponse::success(new StoryProductionPlanResource($plan));
    }

    public function scene(string $uuid, string $planUuid, string $sceneUuid): JsonResponse
    {
        $project = $this->projects->getByUuid($uuid);
        $this->authorize('select', [StoryWorkspace::class, $project]);

        $scene = $this->plans->sceneForPlan($project, $planUuid, $sceneUuid);
        $this->authorize('view', $scene->plan);

        return ApiResponse::success(new StoryProductionPlanSceneResource($scene, $scene->plan->unit_seconds));
    }
}
