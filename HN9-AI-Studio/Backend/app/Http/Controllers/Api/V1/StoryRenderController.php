<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Contracts\Services\ProjectServiceInterface;
use App\Http\Controllers\Controller;
use App\Story\Models\StoryReel;
use App\Story\Models\StoryWorkspace;
use App\Story\Services\StoryRenderService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class StoryRenderController extends Controller
{
    public function __construct(
        private StoryRenderService $renders,
        private ProjectServiceInterface $projects,
    ) {}

    public function store(string $uuid, string $reelUuid): JsonResponse
    {
        $project = $this->projects->getByUuid($uuid);
        $this->authorize('select', [StoryWorkspace::class, $project]);
        $this->authorize('update', $this->reel($project->id, $reelUuid));

        return ApiResponse::success($this->renders->start($project, $reelUuid), 201);
    }

    public function show(string $uuid, string $reelUuid, string $renderUuid): JsonResponse
    {
        $project = $this->projects->getByUuid($uuid);
        $this->authorize('select', [StoryWorkspace::class, $project]);
        $this->authorize('view', $this->reel($project->id, $reelUuid));

        return ApiResponse::success($this->renders->show($project, $reelUuid, $renderUuid));
    }

    public function file(string $uuid, string $reelUuid, string $renderUuid): StreamedResponse
    {
        $project = $this->projects->getByUuid($uuid);
        $this->authorize('select', [StoryWorkspace::class, $project]);
        $this->authorize('view', $this->reel($project->id, $reelUuid));

        return $this->renders->file($project, $reelUuid, $renderUuid);
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
}
