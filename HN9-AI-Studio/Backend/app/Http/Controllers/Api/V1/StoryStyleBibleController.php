<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Contracts\Services\ProjectServiceInterface;
use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateStoryStyleBibleRequest;
use App\Http\Resources\StoryStyleBibleResource;
use App\Story\Contracts\StoryStyleBibleServiceInterface;
use App\Story\Models\StoryWorkspace;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

class StoryStyleBibleController extends Controller
{
    public function __construct(
        private StoryStyleBibleServiceInterface $styles,
        private ProjectServiceInterface $projects,
    ) {}

    public function show(string $uuid): JsonResponse
    {
        $style = $this->authorizedStyle($uuid);

        return ApiResponse::success(new StoryStyleBibleResource($style));
    }

    public function store(string $uuid): JsonResponse
    {
        $project = $this->projects->getByUuid($uuid);
        $this->authorize('select', [StoryWorkspace::class, $project]);

        $style = $this->styles->styleForProject($project);
        $this->authorize('view', $style);

        return $style->wasRecentlyCreated
            ? ApiResponse::created(new StoryStyleBibleResource($style))
            : ApiResponse::success(new StoryStyleBibleResource($style));
    }

    public function update(UpdateStoryStyleBibleRequest $request, string $uuid): JsonResponse
    {
        $project = $this->projects->getByUuid($uuid);
        $this->authorize('select', [StoryWorkspace::class, $project]);

        $style = $this->styles->styleForProject($project);
        $this->authorize('update', $style);

        $updated = $this->styles->updateForProject($project, $request->validated());

        return ApiResponse::success(new StoryStyleBibleResource($updated));
    }

    private function authorizedStyle(string $uuid)
    {
        $project = $this->projects->getByUuid($uuid);
        $this->authorize('select', [StoryWorkspace::class, $project]);

        $style = $this->styles->styleForProject($project);
        $this->authorize('view', $style);

        return $style;
    }
}
