<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Contracts\Services\ProjectServiceInterface;
use App\Http\Controllers\Controller;
use App\Http\Requests\QuickStartStoryRequest;
use App\Models\User;
use App\Story\Models\StoryWorkspace;
use App\Story\Services\StoryQuickStartService;
use App\Story\Support\StoryTemplateCatalog;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

class StoryQuickStartController extends Controller
{
    public function __construct(
        private StoryTemplateCatalog $templates,
        private StoryQuickStartService $quickStart,
        private ProjectServiceInterface $projects,
    ) {}

    public function templates(): JsonResponse
    {
        return ApiResponse::success([
            'templates' => array_values($this->templates->all()),
            'durations' => StoryTemplateCatalog::DURATIONS,
            'aspect_ratios' => $this->templates->aspectRatios(),
            'languages' => StoryTemplateCatalog::LANGUAGES,
        ]);
    }

    public function estimate(QuickStartStoryRequest $request, string $uuid): JsonResponse
    {
        $project = $this->projects->getByUuid($uuid);
        $this->authorize('select', [StoryWorkspace::class, $project]);

        return ApiResponse::success($this->quickStart->estimate($project, $request->validated()));
    }

    public function store(QuickStartStoryRequest $request, string $uuid): JsonResponse
    {
        $project = $this->projects->getByUuid($uuid);
        $this->authorize('select', [StoryWorkspace::class, $project]);
        $user = $request->user();
        if (! $user instanceof User) {
            abort(401);
        }

        return ApiResponse::success($this->quickStart->run($project, $user, $request->validated()));
    }
}
