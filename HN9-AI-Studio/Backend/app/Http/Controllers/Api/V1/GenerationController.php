<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Contracts\Services\AssetServiceInterface;
use App\Contracts\Services\ContentServiceInterface;
use App\Contracts\Services\ExecutionOrchestratorInterface;
use App\Contracts\Services\GenerationRequestServiceInterface;
use App\Contracts\Services\ProjectServiceInterface;
use App\DTOs\Generation\GenerationRequestData;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreProjectInputRequest;
use App\Http\Resources\AssetResource;
use App\Http\Resources\GeneratedContentResource;
use App\Http\Resources\ProjectInputResource;
use App\Models\GeneratedAsset;
use App\Models\GeneratedContent;
use App\Models\ProjectInput;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

final class GenerationController extends Controller
{
    public function __construct(
        private GenerationRequestServiceInterface $generation,
        private ProjectServiceInterface $projects,
        private ContentServiceInterface $content,
        private AssetServiceInterface $assets,
        private ExecutionOrchestratorInterface $orchestrator,
    ) {}

    /**
     * Run the generation pipeline for a project. The controller stays thin: it
     * authorises, builds the request DTO, and hands the whole pipeline to the
     * orchestrator, which owns the ordering of the underlying services.
     */
    public function generate(StoreProjectInputRequest $request, string $uuid): JsonResponse
    {
        $project = $this->projects->getByUuid($uuid);

        $this->authorize('update', $project);

        $payload = $request->validated();
        $payload['project_id'] = $project->getKey();
        $payload['user_id'] = $request->user()?->getKey();

        $dto = GenerationRequestData::fromArray($payload);

        $result = $this->orchestrator->execute($project, $dto, [
            'user' => $request->user(),
        ]);

        $input = $result['project_input'] ?? null;
        $content = $result['content'] ?? null;
        $asset = $result['asset'] ?? null;

        return ApiResponse::created([
            'input' => $input instanceof ProjectInput ? new ProjectInputResource($input) : null,
            'content' => $content instanceof GeneratedContent ? new GeneratedContentResource($content) : null,
            'asset' => $asset instanceof GeneratedAsset ? new AssetResource($asset) : null,
            'dispatch' => $result['dispatch'] ?? null,
        ]);
    }

    public function preview(StoreProjectInputRequest $request, string $uuid): JsonResponse
    {
        $project = $this->projects->getByUuid($uuid);

        $this->authorize('view', $project);

        $payload = $request->validated();
        $payload['project_id'] = $project->getKey();
        $payload['user_id'] = $request->user()?->getKey();

        $dto = GenerationRequestData::fromArray($payload);

        return ApiResponse::success($dto->toArray());
    }

    public function history(string $uuid): JsonResponse
    {
        $project = $this->projects->getByUuid($uuid);

        $this->authorize('view', $project);

        return ApiResponse::success([
            'inputs' => ProjectInputResource::collection($this->generation->forProject($project)),
            'contents' => GeneratedContentResource::collection($this->content->forProject($project)),
            'assets' => AssetResource::collection($this->assets->forProject($project)),
        ]);
    }
}
