<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Contracts\Services\ProjectServiceInterface;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreStoryCharacterRequest;
use App\Http\Requests\UpdateStoryCharacterRequest;
use App\Http\Resources\StoryCharacterResource;
use App\Story\Contracts\StoryCharacterServiceInterface;
use App\Story\Models\StoryCharacter;
use App\Story\Models\StoryWorkspace;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

class StoryCharacterController extends Controller
{
    public function __construct(
        private StoryCharacterServiceInterface $characters,
        private ProjectServiceInterface $projects,
    ) {}

    public function index(string $uuid): JsonResponse
    {
        $project = $this->projects->getByUuid($uuid);
        $this->authorize('select', [StoryWorkspace::class, $project]);

        $items = $this->characters->listForProject($project);

        return ApiResponse::success(StoryCharacterResource::collection($items));
    }

    public function store(StoreStoryCharacterRequest $request, string $uuid): JsonResponse
    {
        $project = $this->projects->getByUuid($uuid);
        $this->authorize('select', [StoryWorkspace::class, $project]);

        $character = $this->characters->create($project, $request->validated());
        $this->authorize('view', $character);

        return ApiResponse::created(new StoryCharacterResource($character));
    }

    public function show(string $uuid, string $characterUuid): JsonResponse
    {
        $character = $this->authorizedCharacter($uuid, $characterUuid, 'view');

        return ApiResponse::success(new StoryCharacterResource($character));
    }

    public function update(UpdateStoryCharacterRequest $request, string $uuid, string $characterUuid): JsonResponse
    {
        $project = $this->projects->getByUuid($uuid);
        $this->authorize('select', [StoryWorkspace::class, $project]);

        $character = $this->characters->getForProject($project, $characterUuid);
        $this->authorize('update', $character);

        $updated = $this->characters->update($project, $characterUuid, $request->validated());

        return ApiResponse::success(new StoryCharacterResource($updated));
    }

    public function destroy(string $uuid, string $characterUuid): JsonResponse
    {
        $project = $this->projects->getByUuid($uuid);
        $this->authorize('select', [StoryWorkspace::class, $project]);

        $character = $this->characters->getForProject($project, $characterUuid);
        $this->authorize('delete', $character);

        $archived = $this->characters->archive($project, $characterUuid);

        return ApiResponse::success(new StoryCharacterResource($archived));
    }

    private function authorizedCharacter(string $uuid, string $characterUuid, string $ability): StoryCharacter
    {
        $project = $this->projects->getByUuid($uuid);
        $this->authorize('select', [StoryWorkspace::class, $project]);

        $character = $this->characters->getForProject($project, $characterUuid);
        $this->authorize($ability, $character);

        return $character;
    }
}
