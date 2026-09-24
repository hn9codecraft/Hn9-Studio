<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Contracts\Services\ProjectServiceInterface;
use App\Contracts\Services\VideoGenerationServiceInterface;
use App\Contracts\Services\VideoReviewServiceInterface;
use App\Contracts\Services\VideoServiceInterface;
use App\DTOs\Video\CreateVideoData;
use App\DTOs\Video\UpdateVideoData;
use App\Exceptions\VideoGenerationException;
use App\Exceptions\VideoWorkflowException;
use App\Http\Controllers\Controller;
use App\Http\Requests\ApproveVideoRequest;
use App\Http\Requests\GenerateVideoRequest;
use App\Http\Requests\NeedsReworkVideoRequest;
use App\Http\Requests\StoreVideoRequest;
use App\Http\Requests\SubmitVideoReviewRequest;
use App\Http\Requests\UpdateVideoRequest;
use App\Http\Resources\VideoResource;
use App\Http\Resources\VideoReviewEventResource;
use App\Models\Video;
use App\Support\ApiResponse;
use App\Support\PageSize;
use App\Support\StorageHelper;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class VideoController extends Controller
{
    public function __construct(
        private ProjectServiceInterface $projects,
        private VideoServiceInterface $videos,
        private VideoGenerationServiceInterface $generation,
        private VideoReviewServiceInterface $reviews,
    ) {}

    public function index(Request $request, string $uuid): JsonResponse
    {
        $project = $this->projects->getByUuid($uuid);

        $this->authorize('view', $project);
        $this->authorize('viewAny', [Video::class, $project]);

        $perPage = PageSize::fromRequest($request, 50);
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

    public function generate(GenerateVideoRequest $request, string $uuid): JsonResponse
    {
        $project = $this->projects->getByUuid($uuid);

        $this->authorize('view', $project);
        $this->authorize('create', [Video::class, $project]);
        $this->authorize('update', $project);

        $result = $this->generation->generate($project, $request->validated(), $request->user());

        return ApiResponse::created([
            'video' => (new VideoResource($result['video']))->resolve(),
            'dispatch' => $result['dispatch'],
        ]);
    }

    public function regenerate(GenerateVideoRequest $request, string $uuid, string $videoUuid): JsonResponse
    {
        $video = $this->videoForProject($uuid, $videoUuid);

        $this->authorize('update', $video);
        $this->authorize('update', $video->project);
        $this->authorize('create', [Video::class, $video->project]);

        if (! $video->allowsRegeneration()) {
            throw VideoWorkflowException::invalidTransition(
                $video->uuid,
                'regenerated',
                $video->statusEnum()->value,
            );
        }

        $result = $this->generation->generate(
            $video->project,
            $request->validated(),
            $request->user(),
            $video,
        );

        return ApiResponse::created([
            'video' => (new VideoResource($result['video']))->resolve(),
            'dispatch' => $result['dispatch'],
        ]);
    }

    public function show(string $uuid, string $videoUuid): JsonResponse
    {
        $video = $this->videoForProject($uuid, $videoUuid);

        $this->authorize('view', $video);

        if ($video->statusEnum()->isInFlight()) {
            $video = $this->generation->refresh($video);
        }

        return ApiResponse::success(new VideoResource($video));
    }

    public function status(string $uuid, string $videoUuid): JsonResponse
    {
        $video = $this->videoForProject($uuid, $videoUuid);

        $this->authorize('view', $video);

        if ($video->statusEnum()->isInFlight()) {
            $video = $this->generation->refresh($video);
        }

        return ApiResponse::success(new VideoResource($video));
    }

    public function file(string $uuid, string $videoUuid): StreamedResponse
    {
        $video = $this->videoForProject($uuid, $videoUuid);

        $this->authorize('view', $video);

        if (! $video->statusEnum()->hasPlayableOutput()) {
            throw VideoGenerationException::notReady();
        }

        $file = $video->file ?? $video->file()->first();

        abort_if($file === null, 404);

        $disk = StorageHelper::disk($file->disk);
        abort_unless(Storage::disk($disk)->exists($file->path), 404);

        return Storage::disk($disk)->response($file->path, 'video.'.($file->extension ?: 'mp4'), [
            'Content-Type' => $file->mime_type ?: 'video/mp4',
            'Content-Disposition' => 'inline',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
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

    public function submitReview(SubmitVideoReviewRequest $request, string $uuid, string $videoUuid): JsonResponse
    {
        $video = $this->videoForProject($uuid, $videoUuid);

        $this->authorize('submit', $video);

        $updated = $this->reviews->submit($video, $request->user(), $request->validated('comment'));

        return ApiResponse::success(new VideoResource($updated));
    }

    public function approve(ApproveVideoRequest $request, string $uuid, string $videoUuid): JsonResponse
    {
        $video = $this->videoForProject($uuid, $videoUuid);

        $this->authorize('review', $video);

        $updated = $this->reviews->approve($video, $request->user(), $request->validated('comment'));

        return ApiResponse::success(new VideoResource($updated));
    }

    public function needsRework(NeedsReworkVideoRequest $request, string $uuid, string $videoUuid): JsonResponse
    {
        $video = $this->videoForProject($uuid, $videoUuid);

        $this->authorize('review', $video);

        $updated = $this->reviews->requestRework($video, $request->user(), (string) $request->validated('comment'));

        return ApiResponse::success(new VideoResource($updated));
    }

    public function reviewHistory(string $uuid, string $videoUuid): JsonResponse
    {
        $video = $this->videoForProject($uuid, $videoUuid);

        $this->authorize('viewReviewHistory', $video);

        return ApiResponse::success(VideoReviewEventResource::collection($this->reviews->history($video)));
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
