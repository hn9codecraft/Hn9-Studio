<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Contracts\Services\GenerationRequestServiceInterface;
use App\Contracts\Services\ProjectServiceInterface;
use App\DTOs\Generation\GenerationRequestData;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreProjectInputRequest;
use App\Http\Resources\ProjectInputResource;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

class ProjectInputController extends Controller
{
    public function __construct(
        private ProjectServiceInterface $projects,
        private GenerationRequestServiceInterface $generation,
    ) {}

    public function index(string $uuid): JsonResponse
    {
        $project = $this->projects->getByUuid($uuid);

        $this->authorize('view', $project);

        $inputs = $this->generation->forProject($project);

        return ApiResponse::success(ProjectInputResource::collection($inputs));
    }

    public function store(StoreProjectInputRequest $request, string $uuid): JsonResponse
    {
        $project = $this->projects->getByUuid($uuid);

        $this->authorize('update', $project);

        $payload = $request->validated();
        $payload['project_id'] = $project->getKey();
        $payload['user_id'] = $request->user()?->getKey();

        $dto = GenerationRequestData::fromArray($payload);

        $input = $this->generation->submit($project, $dto, $request->user());

        return ApiResponse::created(new ProjectInputResource($input));
    }
}
