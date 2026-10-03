<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Contracts\Services\ProjectServiceInterface;
use App\Http\Controllers\Controller;
use App\Http\Requests\GenerateStoryProductionUnitRequest;
use App\Http\Resources\StoryProductionUnitGenerationResource;
use App\Models\Project;
use App\Models\User;
use App\Story\Contracts\StoryProductionPlanServiceInterface;
use App\Story\Contracts\StoryProductionUnitGenerationServiceInterface;
use App\Story\Models\StoryWorkspace;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Starts and reads generation for one Generation Unit. It does not build provider requests.
 */
class StoryProductionUnitGenerationController extends Controller
{
    public function __construct(
        private ProjectServiceInterface $projects,
        private StoryProductionPlanServiceInterface $plans,
        private StoryProductionUnitGenerationServiceInterface $generation,
    ) {}

    public function store(GenerateStoryProductionUnitRequest $request, string $uuid, string $planUuid, string $unitUuid): JsonResponse
    {
        $project = $this->authorizeProject($uuid, $planUuid);
        $result = $this->generation->generate($project, $planUuid, $unitUuid, $request->user(), $request->validated());
        $job = $result['job']->loadMissing('productionUnit');

        return ApiResponse::success([
            'created' => $result['created'],
            'generation' => (new StoryProductionUnitGenerationResource($job))->resolve(),
        ], $result['created'] ? 201 : 200);
    }

    public function show(string $uuid, string $planUuid, string $unitUuid, string $jobUuid): JsonResponse
    {
        $project = $this->authorizeProject($uuid, $planUuid);
        $job = $this->generation->refresh($project, $planUuid, $unitUuid, $jobUuid)->loadMissing('productionUnit');

        return ApiResponse::success([
            'generation' => (new StoryProductionUnitGenerationResource($job))->resolve(),
        ]);
    }

    public function index(string $uuid, string $planUuid, string $unitUuid): JsonResponse
    {
        $project = $this->authorizeProject($uuid, $planUuid);
        $jobs = $this->generation->attempts($project, $planUuid, $unitUuid)->load('productionUnit');

        return ApiResponse::success(StoryProductionUnitGenerationResource::collection($jobs));
    }

    public function cancel(Request $request, string $uuid, string $planUuid, string $unitUuid, string $jobUuid): JsonResponse
    {
        $project = $this->authorizeProject($uuid, $planUuid);
        $actor = $request->user();
        if (! $actor instanceof User) {
            abort(401);
        }
        $job = $this->generation->cancel($project, $planUuid, $unitUuid, $jobUuid, $actor)->loadMissing('productionUnit');

        return ApiResponse::success([
            'generation' => (new StoryProductionUnitGenerationResource($job))->resolve(),
        ]);
    }

    private function authorizeProject(string $uuid, string $planUuid): Project
    {
        $project = $this->projects->getByUuid($uuid);
        $this->authorize('select', [StoryWorkspace::class, $project]);
        $plan = $this->plans->getForProject($project, $planUuid);
        $this->authorize('generate', $plan);

        return $project;
    }
}
