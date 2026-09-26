<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Contracts\Services\ProjectServiceInterface;
use App\Http\Controllers\Controller;
use App\Http\Requests\ReorderStoryScenesRequest;
use App\Http\Requests\StoreStorySceneRequest;
use App\Http\Requests\UpdateStorySceneRequest;
use App\Http\Resources\StorySceneResource;
use App\Story\Contracts\StoryReelServiceInterface;
use App\Story\Contracts\StorySceneServiceInterface;
use App\Story\Models\StoryWorkspace;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

class StorySceneController extends Controller
{
    public function __construct(
        private StorySceneServiceInterface $scenes,
        private StoryReelServiceInterface $reels,
        private ProjectServiceInterface $projects,
    ) {}

    public function index(string $uuid, string $reelUuid): JsonResponse
    {
        $project = $this->projects->getByUuid($uuid);
        $this->authorize('select', [StoryWorkspace::class, $project]);

        $reel = $this->reels->getForProject($project, $reelUuid);
        $this->authorize('view', $reel);

        $items = $this->scenes->listForProjectReel($project, $reelUuid);

        return ApiResponse::success(StorySceneResource::collection($items));
    }

    public function store(StoreStorySceneRequest $request, string $uuid, string $reelUuid): JsonResponse
    {
        $project = $this->projects->getByUuid($uuid);
        $this->authorize('select', [StoryWorkspace::class, $project]);

        $reel = $this->reels->getForProject($project, $reelUuid);
        $this->authorize('update', $reel);

        $scene = $this->scenes->create($project, $reelUuid, $request->validated());
        $this->authorize('view', $scene);

        return ApiResponse::created(new StorySceneResource($scene));
    }

    public function show(string $uuid, string $reelUuid, string $sceneUuid): JsonResponse
    {
        $project = $this->projects->getByUuid($uuid);
        $this->authorize('select', [StoryWorkspace::class, $project]);

        $scene = $this->scenes->getForProjectReel($project, $reelUuid, $sceneUuid);
        $this->authorize('view', $scene);

        return ApiResponse::success(new StorySceneResource($scene));
    }

    public function update(
        UpdateStorySceneRequest $request,
        string $uuid,
        string $reelUuid,
        string $sceneUuid,
    ): JsonResponse {
        $project = $this->projects->getByUuid($uuid);
        $this->authorize('select', [StoryWorkspace::class, $project]);

        $scene = $this->scenes->getForProjectReel($project, $reelUuid, $sceneUuid);
        $this->authorize('update', $scene);

        $updated = $this->scenes->update($project, $reelUuid, $sceneUuid, $request->validated());

        return ApiResponse::success(new StorySceneResource($updated));
    }

    public function duplicate(string $uuid, string $reelUuid, string $sceneUuid): JsonResponse
    {
        $project = $this->projects->getByUuid($uuid);
        $this->authorize('select', [StoryWorkspace::class, $project]);

        $scene = $this->scenes->getForProjectReel($project, $reelUuid, $sceneUuid);
        $this->authorize('update', $scene);

        $copy = $this->scenes->duplicate($project, $reelUuid, $sceneUuid);

        return ApiResponse::created(new StorySceneResource($copy));
    }

    public function archive(string $uuid, string $reelUuid, string $sceneUuid): JsonResponse
    {
        $project = $this->projects->getByUuid($uuid);
        $this->authorize('select', [StoryWorkspace::class, $project]);

        $scene = $this->scenes->getForProjectReel($project, $reelUuid, $sceneUuid);
        $this->authorize('archive', $scene);

        $archived = $this->scenes->archive($project, $reelUuid, $sceneUuid);

        return ApiResponse::success(new StorySceneResource($archived));
    }

    public function reorder(ReorderStoryScenesRequest $request, string $uuid, string $reelUuid): JsonResponse
    {
        $project = $this->projects->getByUuid($uuid);
        $this->authorize('select', [StoryWorkspace::class, $project]);

        $reel = $this->reels->getForProject($project, $reelUuid);
        $this->authorize('update', $reel);

        $items = $this->scenes->reorder($project, $reelUuid, $request->validated('ordered_ids'));

        return ApiResponse::success(StorySceneResource::collection($items));
    }
}
