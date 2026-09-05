<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Contracts\Services\ProjectServiceInterface;
use App\Contracts\Services\VideoServiceInterface;
use App\DTOs\Video\CreateVideoData;
use App\DTOs\Video\UpdateVideoData;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreVideoRequest;
use App\Http\Requests\UpdateVideoRequest;
use App\Http\Resources\VideoResource;
use App\Models\Video;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class VideoController extends Controller
{
    public function __construct(
        private ProjectServiceInterface $projects,
        private VideoServiceInterface $videos,
    ) {}

    public function index(Request $request, string $uuid): JsonResponse
    {
        $project = $this->projects->getByUuid($uuid);

        $this->authorize('view', $project);
        $this->authorize('viewAny', [Video::class, $project]);

        $perPage = (int) $request->query('perPage', 50);
        $filters = $request->only(['status', 'sort', 'order']);
        $page = $this->videos->paginateForProject($project, $perPage, $filters);

        return ApiResponse::success(VideoResource::collection($page->items()), 200, [
            'page' => $page->currentPage(),
            'perPage' => $page->perPage(),
            'total' => $page->total(),
            'lastPage' => $page->lastPage(),
        ]);
    }

    public function store(StoreVideoRequest $request, string $uuid): JsonResponse
    {
        $project = $this->projects->getByUuid($uuid);

        $this->authorize('view', $project);
        $this->authorize('create', [Video::class, $project]);

        $data = CreateVideoData::fromArray(array_merge($request->validated(), [
            'project_id' => $project->getKey(),
        ]));

        $video = $this->videos->create($data, $request->user());

        return ApiResponse::created(new VideoResource($video));
    }

    public function show(string $uuid, string $videoUuid): JsonResponse
    {
        $video = $this->videoForProject($uuid, $videoUuid);

        $this->authorize('view', $video);

        return ApiResponse::success(new VideoResource($video));
    }

    public function update(UpdateVideoRequest $request, string $uuid, string $videoUuid): JsonResponse
    {
        $video = $this->videoForProject($uuid, $videoUuid);

        $this->authorize('update', $video);

        $updated = $this->videos->update($video, UpdateVideoData::fromArray($request->validated()), $request->user());

        return ApiResponse::success(new VideoResource($updated));
    }

    public function destroy(string $uuid, string $videoUuid): JsonResponse
    {
        $video = $this->videoForProject($uuid, $videoUuid);

        $this->authorize('delete', $video);

        $this->videos->delete($video, request()->user());

        return ApiResponse::noContent();
    }

    private function videoForProject(string $projectUuid, string $videoUuid): Video
    {
        $project = $this->projects->getByUuid($projectUuid);

        $this->authorize('view', $project);

        $video = $this->videos->getByUuid($videoUuid);

        abort_unless($video->project_id === $project->getKey(), 404);

        return $video;
    }
}
