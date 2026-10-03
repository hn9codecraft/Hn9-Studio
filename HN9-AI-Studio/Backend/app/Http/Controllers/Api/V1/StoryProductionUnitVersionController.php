<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Contracts\Services\ProjectServiceInterface;
use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Models\User;
use App\Story\Contracts\StoryProductionPlanServiceInterface;
use App\Story\Contracts\StoryProductionUnitVersionServiceInterface;
use App\Story\Models\StoryWorkspace;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Review and selection for Generation Unit versions. It does not generate video.
 */
class StoryProductionUnitVersionController extends Controller
{
    public function __construct(
        private ProjectServiceInterface $projects,
        private StoryProductionPlanServiceInterface $plans,
        private StoryProductionUnitVersionServiceInterface $versions,
    ) {}

    public function index(string $uuid, string $planUuid, string $unitUuid): JsonResponse
    {
        $project = $this->authorizeProject($uuid, $planUuid);

        return ApiResponse::success($this->versions->listForUnit($project, $planUuid, $unitUuid));
    }

    public function show(string $uuid, string $planUuid, string $unitUuid, string $versionUuid): JsonResponse
    {
        $project = $this->authorizeProject($uuid, $planUuid);

        return ApiResponse::success([
            'version' => $this->versions->show($project, $planUuid, $unitUuid, $versionUuid),
        ]);
    }

    public function approve(Request $request, string $uuid, string $planUuid, string $unitUuid, string $versionUuid): JsonResponse
    {
        $project = $this->authorizeProject($uuid, $planUuid);
        $payload = $request->validate(['comment' => ['nullable', 'string', 'max:2000']]);
        $result = $this->versions->approve(
            $project,
            $planUuid,
            $unitUuid,
            $versionUuid,
            $this->actor($request),
            isset($payload['comment']) ? (string) $payload['comment'] : null,
        );

        return ApiResponse::success($result);
    }

    public function requestChanges(Request $request, string $uuid, string $planUuid, string $unitUuid, string $versionUuid): JsonResponse
    {
        $project = $this->authorizeProject($uuid, $planUuid);
        $payload = $request->validate(['comment' => ['required', 'string', 'max:2000']]);
        $result = $this->versions->requestChanges(
            $project,
            $planUuid,
            $unitUuid,
            $versionUuid,
            $this->actor($request),
            (string) $payload['comment'],
        );

        return ApiResponse::success($result);
    }

    public function select(Request $request, string $uuid, string $planUuid, string $unitUuid, string $versionUuid): JsonResponse
    {
        $project = $this->authorizeProject($uuid, $planUuid);

        return ApiResponse::success($this->versions->select(
            $project,
            $planUuid,
            $unitUuid,
            $versionUuid,
            $this->actor($request),
        ));
    }

    public function file(string $uuid, string $planUuid, string $unitUuid, string $versionUuid): StreamedResponse
    {
        $project = $this->authorizeProject($uuid, $planUuid);

        return $this->versions->file($project, $planUuid, $unitUuid, $versionUuid);
    }

    private function authorizeProject(string $uuid, string $planUuid): Project
    {
        $project = $this->projects->getByUuid($uuid);
        $this->authorize('select', [StoryWorkspace::class, $project]);
        $plan = $this->plans->getForProject($project, $planUuid);
        $this->authorize('review', $plan);

        return $project;
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
