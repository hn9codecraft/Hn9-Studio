<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Contracts\Services\ProjectServiceInterface;
use App\Http\Controllers\Controller;
use App\Story\Models\StoryReel;
use App\Story\Models\StoryWorkspace;
use App\Story\Services\StoryTimelineService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StoryTimelineController extends Controller
{
    public function __construct(
        private StoryTimelineService $timelines,
        private ProjectServiceInterface $projects,
    ) {}

    public function show(string $uuid, string $reelUuid): JsonResponse
    {
        $project = $this->projects->getByUuid($uuid);
        $this->authorize('select', [StoryWorkspace::class, $project]);
        $this->authorize('view', $this->reel($project->id, $reelUuid));

        return ApiResponse::success($this->timelines->show($project, $reelUuid));
    }

    public function place(Request $request, string $uuid, string $reelUuid): JsonResponse
    {
        $project = $this->projects->getByUuid($uuid);
        $this->authorize('select', [StoryWorkspace::class, $project]);
        $this->authorize('update', $this->reel($project->id, $reelUuid));
        $payload = $request->validate([
            'media_kind' => ['required', 'string', 'in:video,audio'],
            'source_id' => ['required', 'uuid'],
        ]);

        return ApiResponse::success(
            $this->timelines->place($project, $reelUuid, $payload['media_kind'], $payload['source_id']),
            201,
        );
    }

    public function reorder(Request $request, string $uuid, string $reelUuid): JsonResponse
    {
        $project = $this->projects->getByUuid($uuid);
        $this->authorize('select', [StoryWorkspace::class, $project]);
        $this->authorize('update', $this->reel($project->id, $reelUuid));
        $payload = $request->validate([
            'ordered_ids' => ['required', 'array', 'min:1'],
            'ordered_ids.*' => ['required', 'uuid'],
        ]);

        return ApiResponse::success($this->timelines->reorder($project, $reelUuid, $payload['ordered_ids']));
    }

    public function trim(Request $request, string $uuid, string $reelUuid, string $clipUuid): JsonResponse
    {
        $project = $this->projects->getByUuid($uuid);
        $this->authorize('select', [StoryWorkspace::class, $project]);
        $this->authorize('update', $this->reel($project->id, $reelUuid));
        $payload = $request->validate([
            'in_ms' => ['required', 'integer', 'min:0'],
            'out_ms' => ['required', 'integer', 'min:1'],
        ]);

        return ApiResponse::success($this->timelines->trim(
            $project,
            $reelUuid,
            $clipUuid,
            (int) $payload['in_ms'],
            (int) $payload['out_ms'],
        ));
    }

    public function split(Request $request, string $uuid, string $reelUuid, string $clipUuid): JsonResponse
    {
        $project = $this->projects->getByUuid($uuid);
        $this->authorize('select', [StoryWorkspace::class, $project]);
        $this->authorize('update', $this->reel($project->id, $reelUuid));
        $payload = $request->validate([
            'at_ms' => ['required', 'integer', 'min:1'],
        ]);

        return ApiResponse::success($this->timelines->split($project, $reelUuid, $clipUuid, (int) $payload['at_ms']));
    }

    public function replace(Request $request, string $uuid, string $reelUuid, string $clipUuid): JsonResponse
    {
        $project = $this->projects->getByUuid($uuid);
        $this->authorize('select', [StoryWorkspace::class, $project]);
        $this->authorize('update', $this->reel($project->id, $reelUuid));
        $payload = $request->validate([
            'source_id' => ['required', 'uuid'],
        ]);

        return ApiResponse::success($this->timelines->replace($project, $reelUuid, $clipUuid, $payload['source_id']));
    }

    public function duplicate(string $uuid, string $reelUuid, string $clipUuid): JsonResponse
    {
        $project = $this->projects->getByUuid($uuid);
        $this->authorize('select', [StoryWorkspace::class, $project]);
        $this->authorize('update', $this->reel($project->id, $reelUuid));

        return ApiResponse::success($this->timelines->duplicate($project, $reelUuid, $clipUuid), 201);
    }

    public function destroy(string $uuid, string $reelUuid, string $clipUuid): JsonResponse
    {
        $project = $this->projects->getByUuid($uuid);
        $this->authorize('select', [StoryWorkspace::class, $project]);
        $this->authorize('update', $this->reel($project->id, $reelUuid));

        return ApiResponse::success($this->timelines->delete($project, $reelUuid, $clipUuid));
    }

    public function transition(Request $request, string $uuid, string $reelUuid): JsonResponse
    {
        $project = $this->projects->getByUuid($uuid);
        $this->authorize('select', [StoryWorkspace::class, $project]);
        $this->authorize('update', $this->reel($project->id, $reelUuid));
        $payload = $request->validate([
            'from_clip_id' => ['required', 'uuid'],
            'to_clip_id' => ['required', 'uuid'],
            'type' => ['required', 'string', 'in:cut,dissolve,fade'],
            'duration_ms' => ['nullable', 'integer', 'min:0'],
        ]);

        return ApiResponse::success($this->timelines->transition(
            $project,
            $reelUuid,
            $payload['from_clip_id'],
            $payload['to_clip_id'],
            $payload['type'],
            (int) ($payload['duration_ms'] ?? 0),
        ));
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
