<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Contracts\Services\ProjectServiceInterface;
use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Models\User;
use App\Story\Contracts\StoryProductionPlanServiceInterface;
use App\Story\Models\StoryReel;
use App\Story\Models\StoryWorkspace;
use App\Story\Services\StoryBulkApproveService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StoryBulkApproveController extends Controller
{
    public function __construct(
        private ProjectServiceInterface $projects,
        private StoryProductionPlanServiceInterface $plans,
        private StoryBulkApproveService $bulk,
    ) {}

    public function preview(Request $request, string $uuid): JsonResponse
    {
        [$project, $actor, $kind, $scope, $ids] = $this->payload($request, $uuid, false);

        return ApiResponse::success($this->bulk->preview($project, $kind, $scope, $ids));
    }

    public function store(Request $request, string $uuid): JsonResponse
    {
        [$project, $actor, $kind, $scope, $ids, $hash] = $this->payload($request, $uuid, true);

        return ApiResponse::success($this->bulk->approve($project, $kind, $scope, $ids, $hash, $actor));
    }

    /**
     * @return array{0: Project, 1: User, 2: string, 3: array<string, mixed>, 4: list<string>, 5?: string}
     */
    private function payload(Request $request, string $uuid, bool $approving): array
    {
        $project = $this->projects->getByUuid($uuid);
        $this->authorize('select', [StoryWorkspace::class, $project]);
        $actor = $request->user();
        if (! $actor instanceof User) {
            abort(401);
        }
        $rules = [
            'kind' => ['required', 'string', 'max:64'],
            'scope' => ['sometimes', 'array'],
            'scope.plan_uuid' => ['sometimes', 'nullable', 'uuid'],
            'scope.reel_uuid' => ['sometimes', 'nullable', 'uuid'],
            'ids' => ['present', 'array'],
            'ids.*' => ['string', 'max:64'],
        ];
        if ($approving) {
            $rules['selection_hash'] = ['required', 'string', 'size:64'];
        }
        $payload = $request->validate($rules);
        $kind = (string) $payload['kind'];
        $scope = is_array($payload['scope'] ?? null) ? $payload['scope'] : [];
        $this->authorizeKind($project, $kind, $scope);
        $ids = array_values(array_filter($payload['ids'], 'is_string'));
        if ($approving) {
            return [$project, $actor, $kind, $scope, $ids, (string) $payload['selection_hash']];
        }

        return [$project, $actor, $kind, $scope, $ids];
    }

    /**
     * @param  array<string, mixed>  $scope
     */
    private function authorizeKind(Project $project, string $kind, array $scope): void
    {
        if ($kind === 'unit_version' && is_string($scope['plan_uuid'] ?? null)) {
            $plan = $this->plans->getForProject($project, $scope['plan_uuid']);
            $this->authorize('review', $plan);
        }
        if ($kind === 'scene_audio' && is_string($scope['reel_uuid'] ?? null)) {
            $reel = StoryReel::query()
                ->where('uuid', $scope['reel_uuid'])
                ->whereHas('workspace', static function ($query) use ($project): void {
                    $query->where('project_id', $project->id);
                })
                ->first();
            if ($reel instanceof StoryReel) {
                $this->authorize('review', $reel);
            }
        }
    }
}
