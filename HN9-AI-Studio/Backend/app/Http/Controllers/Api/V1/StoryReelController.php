<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Contracts\Services\ProjectServiceInterface;
use App\Http\Controllers\Controller;
use App\Http\Requests\ReorderStoryReelsRequest;
use App\Http\Requests\StoreStoryReelRequest;
use App\Http\Requests\UpdateStoryReelRequest;
use App\Http\Resources\StoryReelResource;
use App\Story\Contracts\StoryPlanMaterializerInterface;
use App\Story\Contracts\StoryReelServiceInterface;
use App\Story\Models\StoryWorkspace;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

class StoryReelController extends Controller
{
    public function __construct(
        private StoryReelServiceInterface $reels,
        private StoryPlanMaterializerInterface $materializer,
        private ProjectServiceInterface $projects,
    ) {}

    public function index(string $uuid): JsonResponse
    {
        $project = $this->projects->getByUuid($uuid);
        $this->authorize('select', [StoryWorkspace::class, $project]);

        $items = $this->reels->listForProject($project);

        return ApiResponse::success(StoryReelResource::collection($items));
    }

    public function store(StoreStoryReelRequest $request, string $uuid): JsonResponse
    {
        $project = $this->projects->getByUuid($uuid);
        $this->authorize('select', [StoryWorkspace::class, $project]);

        $reel = $this->reels->create($project, $request->validated());
        $this->authorize('view', $reel);

        return ApiResponse::created(new StoryReelResource($reel));
    }

    public function show(string $uuid, string $reelUuid): JsonResponse
    {
        $project = $this->projects->getByUuid($uuid);
        $this->authorize('select', [StoryWorkspace::class, $project]);

        $reel = $this->reels->getForProject($project, $reelUuid);
        $this->authorize('view', $reel);

        return ApiResponse::success(new StoryReelResource($reel));
    }

    public function update(UpdateStoryReelRequest $request, string $uuid, string $reelUuid): JsonResponse
    {
        $project = $this->projects->getByUuid($uuid);
        $this->authorize('select', [StoryWorkspace::class, $project]);

        $reel = $this->reels->getForProject($project, $reelUuid);
        $this->authorize('update', $reel);

        $updated = $this->reels->update($project, $reelUuid, $request->validated());

        return ApiResponse::success(new StoryReelResource($updated));
    }

    public function archive(string $uuid, string $reelUuid): JsonResponse
    {
        $project = $this->projects->getByUuid($uuid);
        $this->authorize('select', [StoryWorkspace::class, $project]);

        $reel = $this->reels->getForProject($project, $reelUuid);
        $this->authorize('archive', $reel);

        $archived = $this->reels->archive($project, $reelUuid);

        return ApiResponse::success(new StoryReelResource($archived));
    }

    public function reorder(ReorderStoryReelsRequest $request, string $uuid): JsonResponse
    {
        $project = $this->projects->getByUuid($uuid);
        $this->authorize('select', [StoryWorkspace::class, $project]);

        $items = $this->reels->reorder($project, $request->validated('ordered_ids'));

        return ApiResponse::success(StoryReelResource::collection($items));
    }

    public function materialize(string $uuid, string $planUuid, string $versionUuid): JsonResponse
    {
        $project = $this->projects->getByUuid($uuid);
        $this->authorize('select', [StoryWorkspace::class, $project]);

        $result = $this->materializer->materialize($project, $planUuid, $versionUuid);
        $this->authorize('view', $result['reel']);

        return ApiResponse::success([
            'created' => $result['created'],
            'scene_count' => $result['scene_count'],
            'reel' => (new StoryReelResource($result['reel']))->resolve(),
        ], $result['created'] ? 201 : 200);
    }
}
