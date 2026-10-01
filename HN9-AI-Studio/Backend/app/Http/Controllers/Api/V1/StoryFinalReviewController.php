<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Contracts\Services\ProjectServiceInterface;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Story\Models\StoryReel;
use App\Story\Models\StoryWorkspace;
use App\Story\Services\StoryFinalReviewService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StoryFinalReviewController extends Controller
{
    public function __construct(
        private StoryFinalReviewService $reviews,
        private ProjectServiceInterface $projects,
    ) {}

    public function show(string $uuid, string $reelUuid, string $renderUuid): JsonResponse
    {
        $project = $this->projects->getByUuid($uuid);
        $this->authorize('select', [StoryWorkspace::class, $project]);
        $this->authorize('view', $this->reel($project->id, $reelUuid));

        return ApiResponse::success($this->reviews->show($project, $reelUuid, $renderUuid));
    }

    public function submit(Request $request, string $uuid, string $reelUuid, string $renderUuid): JsonResponse
    {
        $project = $this->projects->getByUuid($uuid);
        $this->authorize('select', [StoryWorkspace::class, $project]);
        $this->authorize('review', $this->reel($project->id, $reelUuid));
        $payload = $request->validate(['comment' => ['nullable', 'string', 'max:5000']]);

        return ApiResponse::success($this->reviews->submit(
            $project,
            $reelUuid,
            $renderUuid,
            $this->actor($request),
            $payload['comment'] ?? null,
        ));
    }

    public function approve(Request $request, string $uuid, string $reelUuid, string $renderUuid): JsonResponse
    {
        $project = $this->projects->getByUuid($uuid);
        $this->authorize('select', [StoryWorkspace::class, $project]);
        $this->authorize('review', $this->reel($project->id, $reelUuid));
        $payload = $request->validate(['comment' => ['nullable', 'string', 'max:5000']]);

        return ApiResponse::success($this->reviews->approve(
            $project,
            $reelUuid,
            $renderUuid,
            $this->actor($request),
            $payload['comment'] ?? null,
        ));
    }

    public function rework(Request $request, string $uuid, string $reelUuid, string $renderUuid): JsonResponse
    {
        $project = $this->projects->getByUuid($uuid);
        $this->authorize('select', [StoryWorkspace::class, $project]);
        $this->authorize('review', $this->reel($project->id, $reelUuid));
        $payload = $request->validate([
            'comment' => ['required', 'string', 'max:5000'],
            'target_kind' => ['required', 'string', 'in:timeline,scene_version'],
            'target_id' => ['required', 'uuid'],
        ]);

        return ApiResponse::success($this->reviews->rework(
            $project,
            $reelUuid,
            $renderUuid,
            $this->actor($request),
            $payload['comment'],
            $payload['target_kind'],
            $payload['target_id'],
        ));
    }

    private function actor(Request $request): User
    {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(401);
        }

        return $user;
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
