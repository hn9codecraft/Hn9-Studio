<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Contracts\Services\ProjectServiceInterface;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Story\Contracts\StoryProductionPlanServiceInterface;
use App\Story\Enums\StoryVideoCapability;
use App\Story\Models\StoryScene;
use App\Story\Models\StoryWorkspace;
use App\Story\Services\StoryPromptPreviewService;
use App\Story\Services\StoryReviewService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class StoryPromptPreviewController extends Controller
{
    public function __construct(
        private ProjectServiceInterface $projects,
        private StoryProductionPlanServiceInterface $plans,
        private StoryPromptPreviewService $previews,
    ) {}

    public function unit(Request $request, string $uuid, string $planUuid, string $unitUuid): JsonResponse
    {
        $project = $this->authorizeUnit($uuid, $planUuid);
        $payload = $request->validate([
            'capability' => ['required', 'string', Rule::in([
                StoryVideoCapability::TextToVideo->value,
                StoryVideoCapability::ImageToVideo->value,
                StoryVideoCapability::ReferenceToVideo->value,
            ])],
            'intent' => ['sometimes', 'nullable', 'string', 'regex:/^[A-Za-z0-9_-]{1,64}$/'],
            'instruction' => ['sometimes', 'nullable', 'string', 'max:500'],
            'aspect_ratio' => ['sometimes', 'nullable', 'string', 'max:16'],
            'inputs' => ['sometimes', 'array', 'max:8'],
            'inputs.*.type' => ['required', 'string', 'max:32'],
            'inputs.*.asset_id' => ['required', 'uuid'],
        ]);

        return ApiResponse::success($this->previews->previewUnit($project, $planUuid, $unitUuid, $payload));
    }

    public function savePrompt(Request $request, string $uuid, string $planUuid, string $unitUuid): JsonResponse
    {
        $project = $this->authorizeUnit($uuid, $planUuid);
        $payload = $request->validate([
            'prompt' => ['required', 'string', 'max:4000'],
        ]);
        $this->previews->saveUnitPrompt($project, $planUuid, $unitUuid, $payload['prompt'], $this->actor($request));

        return ApiResponse::success(['saved' => true]);
    }

    public function resetPrompt(Request $request, string $uuid, string $planUuid, string $unitUuid): JsonResponse
    {
        $project = $this->authorizeUnit($uuid, $planUuid);
        $this->previews->resetUnitPrompt($project, $planUuid, $unitUuid, $this->actor($request));

        return ApiResponse::success(['saved' => false]);
    }

    public function regenerate(Request $request, string $uuid, string $reelUuid, string $sceneUuid): JsonResponse
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
            'reference_uuid' => ['nullable', 'uuid'],
            'reference_id' => ['nullable', 'uuid'],
        ]);

        return ApiResponse::success($this->previews->previewSceneRegenerate($project, $reelUuid, $sceneUuid, $payload));
    }

    private function authorizeUnit(string $uuid, string $planUuid): \App\Models\Project
    {
        $project = $this->projects->getByUuid($uuid);
        $this->authorize('select', [StoryWorkspace::class, $project]);
        $plan = $this->plans->getForProject($project, $planUuid);
        $this->authorize('generate', $plan);

        return $project;
    }

    private function scene(int $projectId, string $reelUuid, string $sceneUuid): StoryScene
    {
        $scene = StoryScene::query()
            ->where('uuid', $sceneUuid)
            ->whereHas('reel', static function ($query) use ($projectId, $reelUuid): void {
                $query->where('uuid', $reelUuid)->whereHas('workspace', static function ($query) use ($projectId): void {
                    $query->where('project_id', $projectId);
                });
            })
            ->first();
        if (! $scene instanceof StoryScene) {
            abort(404);
        }

        return $scene;
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
