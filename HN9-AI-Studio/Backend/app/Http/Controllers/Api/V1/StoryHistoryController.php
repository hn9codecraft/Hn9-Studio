<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Contracts\Services\ProjectServiceInterface;
use App\Http\Controllers\Controller;
use App\Story\Models\StoryWorkspace;
use App\Story\Services\StoryUsageService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

/**
 * Read-only Story generation history. There is no endpoint here that starts generation.
 */
class StoryHistoryController extends Controller
{
    public function __construct(
        private StoryUsageService $usage,
        private ProjectServiceInterface $projects,
    ) {}

    public function index(string $uuid): JsonResponse
    {
        $project = $this->projects->getByUuid($uuid);
        $this->authorize('select', [StoryWorkspace::class, $project]);

        return ApiResponse::success($this->usage->history($project));
    }
}
