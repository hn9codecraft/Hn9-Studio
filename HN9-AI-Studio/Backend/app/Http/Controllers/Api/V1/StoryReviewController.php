<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Contracts\Services\ProjectServiceInterface;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Story\Models\StoryReel;
use App\Story\Models\StoryScene;
use App\Story\Models\StoryWorkspace;
use App\Story\Services\StoryReviewService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class StoryReviewController extends Controller
{
    public function __construct(
        private StoryReviewService $reviews,
        private ProjectServiceInterface $projects,
    ) {}

    public function sceneVersions(string $uuid, string $reelUuid, string $sceneUuid): JsonResponse
    {
        $project = $this->projects->getByUuid($uuid);
        $this->authorize('select', [StoryWorkspace::class, $project]);
        $scene = $this->scene($project->id, $reelUuid, $sceneUuid);
        $this->authorize('view', $scene);

        return ApiResponse::success($this->reviews->sceneVersions($project, $reelUuid, $sceneUuid));
    }

    public function scenePreview(string $uuid, string $reelUuid, string $sceneUuid): JsonResponse
    {
        $project = $this->projects->getByUuid($uuid);
        $this->authorize('select', [StoryWorkspace::class, $project]);
        $this->authorize('view', $this->scene($project->id, $reelUuid, $sceneUuid));

        return ApiResponse::success($this->reviews->scenePreview($project, $reelUuid, $sceneUuid));
    }

    public function sceneFile(string $uuid, string $reelUuid, string $sceneUuid): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        $project = $this->projects->getByUuid($uuid);
        $this->authorize('select', [StoryWorkspace::class, $project]);
        $this->authorize('view', $this->scene($project->id, $reelUuid, $sceneUuid));

        return $this->reviews->sceneFile($project, $reelUuid, $sceneUuid);
    }

    public function commentScene(Request $request, string $uuid, string $reelUuid, string $sceneUuid): JsonResponse
    {
        $project = $this->projects->getByUuid($uuid);
        $this->authorize('select', [StoryWorkspace::class, $project]);
        $scene = $this->scene($project->id, $reelUuid, $sceneUuid);
        $this->authorize('review', $scene);
        $payload = $request->validate(['body' => ['required', 'string', 'max:5000']]);

        return ApiResponse::success($this->reviews->commentOnScene(
            $project,
            $reelUuid,
            $sceneUuid,
            $this->actor($request),
            $payload['body'],
        ), 201);
    }

    public function submitScene(Request $request, string $uuid, string $reelUuid, string $sceneUuid): JsonResponse
    {
        $project = $this->projects->getByUuid($uuid);
        $this->authorize('select', [StoryWorkspace::class, $project]);
        $this->authorize('review', $this->scene($project->id, $reelUuid, $sceneUuid));
        $payload = $request->validate(['comment' => ['nullable', 'string', 'max:5000']]);

        return ApiResponse::success($this->reviews->submitScene($project, $reelUuid, $sceneUuid, $payload['comment'] ?? null));
    }

    public function approveScene(Request $request, string $uuid, string $reelUuid, string $sceneUuid): JsonResponse
    {
        $project = $this->projects->getByUuid($uuid);
        $this->authorize('select', [StoryWorkspace::class, $project]);
        $this->authorize('review', $this->scene($project->id, $reelUuid, $sceneUuid));
        $payload = $request->validate(['comment' => ['nullable', 'string', 'max:5000']]);

        return ApiResponse::success($this->reviews->approveScene(
            $project,
            $reelUuid,
            $sceneUuid,
            $this->actor($request),
            $payload['comment'] ?? null,
        ));
    }

    public function reworkScene(Request $request, string $uuid, string $reelUuid, string $sceneUuid): JsonResponse
    {
        $project = $this->projects->getByUuid($uuid);
        $this->authorize('select', [StoryWorkspace::class, $project]);
        $this->authorize('review', $this->scene($project->id, $reelUuid, $sceneUuid));
        $payload = $request->validate(['comment' => ['required', 'string', 'max:5000']]);

        return ApiResponse::success($this->reviews->reworkScene(
            $project,
            $reelUuid,
            $sceneUuid,
            $this->actor($request),
            $payload['comment'],
        ));
    }

    public function regenerateScene(Request $request, string $uuid, string $reelUuid, string $sceneUuid): JsonResponse
    {
        $project = $this->projects->getByUuid($uuid);
        $this->authorize('select', [StoryWorkspace::class, $project]);
        $this->authorize('review', $this->scene($project->id, $reelUuid, $sceneUuid));
        $payload = $request->validate([
            'comment' => ['nullable', 'string', 'max:5000'],
            'mode' => ['nullable', 'string', Rule::in([
                StoryReviewService::MODE_TEXT,
                StoryReviewService::MODE_IMAGE,
                StoryReviewService::MODE_REFERENCE,
            ])],
            'prompt' => ['nullable', 'string', 'max:5000'],
            'reference_id' => ['nullable', 'uuid'],
        ]);

        return ApiResponse::success($this->reviews->regenerateScene(
            $project,
            $reelUuid,
            $sceneUuid,
            $payload['comment'] ?? null,
            $payload['mode'] ?? StoryReviewService::MODE_TEXT,
            $payload['prompt'] ?? null,
            $payload['reference_id'] ?? null,
        ), 201);
    }

    public function reelSceneStatus(string $uuid, string $reelUuid): JsonResponse
    {
        $project = $this->projects->getByUuid($uuid);
        $this->authorize('select', [StoryWorkspace::class, $project]);
        $this->authorize('view', $this->reel($project->id, $reelUuid));

        return ApiResponse::success($this->reviews->reelSceneStatus($project, $reelUuid));
    }

    public function editScene(Request $request, string $uuid, string $reelUuid, string $sceneUuid, string $versionUuid): JsonResponse
    {
        $project = $this->projects->getByUuid($uuid);
        $this->authorize('select', [StoryWorkspace::class, $project]);
        $this->authorize('review', $this->scene($project->id, $reelUuid, $sceneUuid));
        $payload = $request->validate(['instruction' => ['required', 'string', 'max:5000']]);
        $result = $this->reviews->editScene($project, $reelUuid, $sceneUuid, $versionUuid, $payload['instruction']);

        return ApiResponse::success($result, ($result['created'] ?? false) === true ? 201 : 200);
    }

    public function extendScene(Request $request, string $uuid, string $reelUuid, string $sceneUuid, string $versionUuid): JsonResponse
    {
        $project = $this->projects->getByUuid($uuid);
        $this->authorize('select', [StoryWorkspace::class, $project]);
        $this->authorize('review', $this->scene($project->id, $reelUuid, $sceneUuid));
        $payload = $request->validate(['instruction' => ['required', 'string', 'max:5000']]);
        $result = $this->reviews->extendScene($project, $reelUuid, $sceneUuid, $versionUuid, $payload['instruction']);

        return ApiResponse::success($result, ($result['created'] ?? false) === true ? 201 : 200);
    }

    public function versionStatus(string $uuid, string $reelUuid, string $sceneUuid, string $versionUuid): JsonResponse
    {
        $project = $this->projects->getByUuid($uuid);
        $this->authorize('select', [StoryWorkspace::class, $project]);
        $this->authorize('view', $this->scene($project->id, $reelUuid, $sceneUuid));

        return ApiResponse::success($this->reviews->versionStatus($project, $reelUuid, $sceneUuid, $versionUuid));
    }

    public function versionFile(string $uuid, string $reelUuid, string $sceneUuid, string $versionUuid): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        $project = $this->projects->getByUuid($uuid);
        $this->authorize('select', [StoryWorkspace::class, $project]);
        $this->authorize('view', $this->scene($project->id, $reelUuid, $sceneUuid));

        return $this->reviews->versionFile($project, $reelUuid, $sceneUuid, $versionUuid);
    }

    public function reelVersions(string $uuid, string $reelUuid): JsonResponse
    {
        $project = $this->projects->getByUuid($uuid);
        $this->authorize('select', [StoryWorkspace::class, $project]);
        $this->authorize('view', $this->reel($project->id, $reelUuid));

        return ApiResponse::success($this->reviews->reelVersions($project, $reelUuid));
    }

    public function commentReel(Request $request, string $uuid, string $reelUuid): JsonResponse
    {
        $project = $this->projects->getByUuid($uuid);
        $this->authorize('select', [StoryWorkspace::class, $project]);
        $this->authorize('review', $this->reel($project->id, $reelUuid));
        $payload = $request->validate(['body' => ['required', 'string', 'max:5000']]);

        return ApiResponse::success($this->reviews->commentOnReel(
            $project,
            $reelUuid,
            $this->actor($request),
            $payload['body'],
        ), 201);
    }

    public function submitReel(Request $request, string $uuid, string $reelUuid): JsonResponse
    {
        $project = $this->projects->getByUuid($uuid);
        $this->authorize('select', [StoryWorkspace::class, $project]);
        $this->authorize('review', $this->reel($project->id, $reelUuid));
        $payload = $request->validate(['comment' => ['nullable', 'string', 'max:5000']]);

        return ApiResponse::success($this->reviews->submitReel($project, $reelUuid, $payload['comment'] ?? null));
    }

    public function approveReel(Request $request, string $uuid, string $reelUuid): JsonResponse
    {
        $project = $this->projects->getByUuid($uuid);
        $this->authorize('select', [StoryWorkspace::class, $project]);
        $this->authorize('review', $this->reel($project->id, $reelUuid));
        $payload = $request->validate(['comment' => ['nullable', 'string', 'max:5000']]);

        return ApiResponse::success($this->reviews->approveReel(
            $project,
            $reelUuid,
            $this->actor($request),
            $payload['comment'] ?? null,
        ));
    }

    public function reworkReel(Request $request, string $uuid, string $reelUuid): JsonResponse
    {
        $project = $this->projects->getByUuid($uuid);
        $this->authorize('select', [StoryWorkspace::class, $project]);
        $this->authorize('review', $this->reel($project->id, $reelUuid));
        $payload = $request->validate(['comment' => ['required', 'string', 'max:5000']]);

        return ApiResponse::success($this->reviews->reworkReel(
            $project,
            $reelUuid,
            $this->actor($request),
            $payload['comment'],
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

    private function scene(int $projectId, string $reelUuid, string $sceneUuid): StoryScene
    {
        $scene = StoryScene::query()
            ->where('uuid', $sceneUuid)
            ->whereHas('reel', static function ($query) use ($projectId, $reelUuid): void {
                $query->where('uuid', $reelUuid)
                    ->whereHas('workspace', static function ($workspace) use ($projectId): void {
                        $workspace->where('project_id', $projectId);
                    });
            })
            ->first();

        if ($scene === null) {
            abort(404);
        }

        return $scene;
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
