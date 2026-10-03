<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Contracts\Services\ProjectServiceInterface;
use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Models\User;
use App\Story\Contracts\StoryProductionPlanServiceInterface;
use App\Story\Contracts\StorySceneAssemblyServiceInterface;
use App\Story\Models\StoryWorkspace;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Builds one scene video from the selected unit versions. It does not generate
 * video and it does not choose a provider.
 */
class StorySceneAssemblyController extends Controller
{
    public function __construct(
        private ProjectServiceInterface $projects,
        private StoryProductionPlanServiceInterface $plans,
        private StorySceneAssemblyServiceInterface $assemblies,
    ) {}

    public function store(Request $request, string $uuid, string $planUuid, string $sceneUuid): JsonResponse
    {
        $project = $this->authorizeProject($uuid, $planUuid);
        $result = $this->assemblies->assemble($project, $planUuid, $sceneUuid, $this->actor($request));

        return ApiResponse::success($result, $result['created'] ? 201 : 200);
    }

    public function index(string $uuid, string $planUuid, string $sceneUuid): JsonResponse
    {
        $project = $this->authorizeProject($uuid, $planUuid);

        return ApiResponse::success($this->assemblies->listForScene($project, $planUuid, $sceneUuid));
    }

    public function workspace(string $uuid, string $planUuid, string $sceneUuid): JsonResponse
    {
        $project = $this->authorizeProject($uuid, $planUuid);

        return ApiResponse::success($this->assemblies->workspace($project, $planUuid, $sceneUuid));
    }

    public function show(string $uuid, string $planUuid, string $sceneUuid, string $assemblyUuid): JsonResponse
    {
        $project = $this->authorizeProject($uuid, $planUuid);

        return ApiResponse::success([
            'assembly' => $this->assemblies->show($project, $planUuid, $sceneUuid, $assemblyUuid),
        ]);
    }

    public function file(string $uuid, string $planUuid, string $sceneUuid, string $assemblyUuid): StreamedResponse
    {
        $project = $this->authorizeProject($uuid, $planUuid);

        return $this->assemblies->file($project, $planUuid, $sceneUuid, $assemblyUuid);
    }

    private function authorizeProject(string $uuid, string $planUuid): Project
    {
        $project = $this->projects->getByUuid($uuid);
        $this->authorize('select', [StoryWorkspace::class, $project]);
        $plan = $this->plans->getForProject($project, $planUuid);
        $this->authorize('review', $plan);

        return $project;
    }

    private function actor(Request $request): User
    {
        $actor = $request->user();
        if (! $actor instanceof User) {
            abort(401);
        }

        return $actor;
    }
}
