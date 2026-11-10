<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Contracts\Services\ProjectServiceInterface;
use App\Http\Controllers\Controller;
use App\Story\Models\StoryWorkspace;
use App\Story\Services\StoryProgressService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

class StoryProgressController extends Controller
{
    public function __construct(
        private ProjectServiceInterface $projects,
        private StoryProgressService $progress,
    ) {}

    public function show(string $uuid): JsonResponse
    {
        $project = $this->projects->getByUuid($uuid);
        $this->authorize('select', [StoryWorkspace::class, $project]);

        return ApiResponse::success($this->progress->forProject($project));
    }
}
