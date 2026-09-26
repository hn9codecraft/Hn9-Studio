<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Contracts\Services\ProjectServiceInterface;
use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateStoryBibleRequest;
use App\Http\Resources\StoryBibleResource;
use App\Story\Contracts\StoryBibleServiceInterface;
use App\Story\Models\StoryWorkspace;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

class StoryBibleController extends Controller
{
    public function __construct(
        private StoryBibleServiceInterface $bibles,
        private ProjectServiceInterface $projects,
    ) {}

    public function show(string $uuid): JsonResponse
    {
        $bible = $this->authorizedBible($uuid);

        return ApiResponse::success(new StoryBibleResource($bible));
    }

    public function store(string $uuid): JsonResponse
    {
        $project = $this->projects->getByUuid($uuid);
        $this->authorize('select', [StoryWorkspace::class, $project]);

        $bible = $this->bibles->bibleForProject($project);
        $this->authorize('view', $bible);

        $wasRecentlyCreated = $bible->wasRecentlyCreated;

        return $wasRecentlyCreated
            ? ApiResponse::created(new StoryBibleResource($bible))
            : ApiResponse::success(new StoryBibleResource($bible));
    }

    public function update(UpdateStoryBibleRequest $request, string $uuid): JsonResponse
    {
        $project = $this->projects->getByUuid($uuid);
        $this->authorize('select', [StoryWorkspace::class, $project]);

        $bible = $this->bibles->bibleForProject($project);
        $this->authorize('update', $bible);

        $updated = $this->bibles->updateForProject($project, $request->validated());

        return ApiResponse::success(new StoryBibleResource($updated));
    }

    private function authorizedBible(string $uuid)
    {
        $project = $this->projects->getByUuid($uuid);
        $this->authorize('select', [StoryWorkspace::class, $project]);

        $bible = $this->bibles->bibleForProject($project);
        $this->authorize('view', $bible);

        return $bible;
    }
}
