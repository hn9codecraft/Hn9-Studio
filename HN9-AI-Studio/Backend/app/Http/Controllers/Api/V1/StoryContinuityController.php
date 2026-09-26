<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Contracts\Services\ProjectServiceInterface;
use App\Http\Controllers\Controller;
use App\Story\Models\StoryWorkspace;
use App\Story\Services\StoryContinuityService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

class StoryContinuityController extends Controller
{
    public function __construct(
        private StoryContinuityService $continuity,
        private ProjectServiceInterface $projects,
    ) {}

    public function show(string $uuid, string $reelUuid, string $sceneUuid): JsonResponse
    {
        $project = $this->projects->getByUuid($uuid);
        $this->authorize('select', [StoryWorkspace::class, $project]);

        return ApiResponse::success($this->continuity->packageForScene($project, $reelUuid, $sceneUuid));
    }
}
