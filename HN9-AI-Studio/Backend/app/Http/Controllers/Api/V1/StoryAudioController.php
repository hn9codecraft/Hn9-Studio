<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Contracts\Services\ProjectServiceInterface;
use App\Http\Controllers\Controller;
use App\Http\Requests\CreateStorySceneAudioRequest;
use App\Story\Enums\StoryAudioRole;
use App\Story\Models\StoryReel;
use App\Story\Models\StoryScene;
use App\Story\Models\StoryWorkspace;
use App\Story\Services\StoryAudioService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StoryAudioController extends Controller
{
    public function __construct(
        private StoryAudioService $audio,
        private ProjectServiceInterface $projects,
    ) {}

    public function roles(): JsonResponse
    {
        $this->authorize('viewAny', StoryWorkspace::class);

        return ApiResponse::success($this->audio->roles());
    }

    public function index(Request $request, string $uuid, string $reelUuid, string $sceneUuid): JsonResponse
    {
        $project = $this->projects->getByUuid($uuid);
        $this->authorize('select', [StoryWorkspace::class, $project]);
        $scene = $this->scene($project->id, $reelUuid, $sceneUuid);
        $this->authorize('view', $scene);

        $role = null;
        $raw = $request->query('role');
        if (is_string($raw) && $raw !== '') {
            $role = StoryAudioRole::tryFrom($raw);
            if ($role === null) {
                return ApiResponse::error('Invalid audio role.', 'INVALID_INPUT', 422);
            }
        }

        return ApiResponse::success($this->audio->list($project, $reelUuid, $sceneUuid, $role));
    }

    public function reelIndex(string $uuid, string $reelUuid): JsonResponse
    {
        $project = $this->projects->getByUuid($uuid);
        $this->authorize('select', [StoryWorkspace::class, $project]);
        $reel = StoryReel::query()
            ->where('uuid', $reelUuid)
            ->whereHas('workspace', static function ($query) use ($project): void {
                $query->where('project_id', $project->id);
            })
            ->firstOrFail();
        $this->authorize('view', $reel);

        return ApiResponse::success($this->audio->listForReel($project, $reel));
    }

    public function store(
        CreateStorySceneAudioRequest $request,
        string $uuid,
        string $reelUuid,
        string $sceneUuid,
    ): JsonResponse {
        $project = $this->projects->getByUuid($uuid);
        $this->authorize('select', [StoryWorkspace::class, $project]);
        $scene = $this->scene($project->id, $reelUuid, $sceneUuid);
        $this->authorize('review', $scene);

        $payload = $request->validated();
        $role = StoryAudioRole::from((string) $payload['role']);
        $result = $this->audio->create(
            $project,
            $reelUuid,
            $sceneUuid,
            $role,
            (string) $payload['prompt'],
            isset($payload['version_id']) ? (string) $payload['version_id'] : null,
        );

        return ApiResponse::success($result, ($result['created'] ?? true) ? 201 : 200);
    }

    public function show(string $uuid, string $reelUuid, string $sceneUuid, string $audioUuid): JsonResponse
    {
        $project = $this->projects->getByUuid($uuid);
        $this->authorize('select', [StoryWorkspace::class, $project]);
        $this->authorize('view', $this->scene($project->id, $reelUuid, $sceneUuid));

        return ApiResponse::success($this->audio->status($project, $reelUuid, $sceneUuid, $audioUuid));
    }

    public function file(string $uuid, string $reelUuid, string $sceneUuid, string $audioUuid): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        $project = $this->projects->getByUuid($uuid);
        $this->authorize('select', [StoryWorkspace::class, $project]);
        $this->authorize('view', $this->scene($project->id, $reelUuid, $sceneUuid));

        return $this->audio->file($project, $reelUuid, $sceneUuid, $audioUuid);
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
            ->with(['reel.workspace.project'])
            ->firstOrFail();

        return $scene;
    }
}
