<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Contracts\Services\ProjectServiceInterface;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreStorySubtitleRequest;
use App\Models\User;
use App\Story\Exceptions\StoryException;
use App\Story\Models\StoryWorkspace;
use App\Story\Services\StoryRenderService;
use App\Story\Services\StorySubtitleService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class StorySubtitleController extends Controller
{
    public function __construct(
        private StorySubtitleService $subtitles,
        private StoryRenderService $renders,
        private ProjectServiceInterface $projects,
    ) {}

    public function index(string $uuid, string $reelUuid, string $renderUuid): JsonResponse
    {
        $project = $this->projects->getByUuid($uuid);
        $this->authorize('select', [StoryWorkspace::class, $project]);

        return ApiResponse::success($this->subtitles->index($project, $reelUuid, $renderUuid));
    }

    public function store(StoreStorySubtitleRequest $request, string $uuid, string $reelUuid, string $renderUuid): JsonResponse
    {
        $project = $this->projects->getByUuid($uuid);
        $this->authorize('select', [StoryWorkspace::class, $project]);
        $user = $request->user();
        if (! $user instanceof User) {
            abort(401);
        }

        return ApiResponse::success($this->subtitles->build($project, $reelUuid, $renderUuid, $user));
    }

    public function file(Request $request, string $uuid, string $reelUuid, string $renderUuid, string $trackUuid): Response
    {
        $project = $this->projects->getByUuid($uuid);
        $this->authorize('select', [StoryWorkspace::class, $project]);
        $format = (string) $request->query('format', 'srt');
        if (! in_array($format, ['srt', 'vtt'], true)) {
            throw new StoryException('Choose srt or vtt.', 'story_subtitle_invalid', 422);
        }
        $file = $this->subtitles->file($project, $reelUuid, $renderUuid, $trackUuid, $format);

        return response($file['body'], 200, [
            'Content-Type' => $file['content_type'],
            'Content-Disposition' => 'attachment; filename="'.$file['filename'].'"',
        ]);
    }

    public function burn(string $uuid, string $reelUuid, string $renderUuid, string $trackUuid): JsonResponse
    {
        $project = $this->projects->getByUuid($uuid);
        $this->authorize('select', [StoryWorkspace::class, $project]);
        $user = request()->user();
        if (! $user instanceof User) {
            abort(401);
        }

        $payload = $this->renders->burnSubtitles($project, $reelUuid, $renderUuid, $trackUuid, $user);
        unset($payload['disk'], $payload['path']);

        return ApiResponse::success($payload);
    }
}
