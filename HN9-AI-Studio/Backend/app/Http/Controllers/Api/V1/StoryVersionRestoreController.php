<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Contracts\Services\ProjectServiceInterface;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Story\Models\StoryProductionPlan;
use App\Story\Models\StoryReel;
use App\Story\Models\StoryWorkspace;
use App\Story\Services\StoryVersionRestoreService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StoryVersionRestoreController extends Controller
{
    public function __construct(
        private StoryVersionRestoreService $restores,
        private ProjectServiceInterface $projects,
    ) {}

    public function index(string $uuid, string $planUuid, string $sceneUuid): JsonResponse
    {
        $project = $this->authorizeProject($uuid, $planUuid);

        return ApiResponse::success($this->restores->listForScene($project, $planUuid, $sceneUuid));
    }

    public function restoreAssembly(Request $request, string $uuid, string $planUuid, string $sceneUuid, string $assemblyUuid): JsonResponse
    {
        $project = $this->authorizeProject($uuid, $planUuid);

        return ApiResponse::success($this->restores->restoreAssembly(
            $project,
            $planUuid,
            $sceneUuid,
            $assemblyUuid,
            $this->actor($request),
        ));
    }

    public function restoreSceneVersion(Request $request, string $uuid, string $reelUuid, string $sceneUuid, string $versionUuid): JsonResponse
    {
        $project = $this->projects->getByUuid($uuid);
        $this->authorize('select', [StoryWorkspace::class, $project]);
        $this->authorize('update', $this->reel($project->id, $reelUuid));

        return ApiResponse::success($this->restores->restoreSceneVersion(
            $project,
            $reelUuid,
            $sceneUuid,
            $versionUuid,
            $this->actor($request),
        ));
    }

    private function authorizeProject(string $uuid, string $planUuid): \App\Models\Project
    {
        $project = $this->projects->getByUuid($uuid);
        $this->authorize('select', [StoryWorkspace::class, $project]);
        $plan = StoryProductionPlan::query()
            ->where('uuid', $planUuid)
            ->whereHas('workspace', static function ($query) use ($project): void {
                $query->where('project_id', $project->id);
            })
            ->first();
        if ($plan === null) {
            abort(404);
        }
        $this->authorize('review', $plan);

        return $project;
    }

    private function reel(int $projectId, string $reelUuid): StoryReel
    {
        $reel = StoryReel::query()
            ->where('uuid', $reelUuid)
            ->whereHas('workspace', static function ($query) use ($projectId): void {
                $query->where('project_id', $projectId);
            })
            ->first();
        if ($reel === null) {
            abort(404);
        }

        return $reel;
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
