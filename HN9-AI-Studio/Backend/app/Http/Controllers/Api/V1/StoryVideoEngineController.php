<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Contracts\Services\ProjectServiceInterface;
use App\Http\Controllers\Controller;
use App\Http\Requests\PrepareStoryVideoJobRequest;
use App\Http\Requests\ValidateStoryVideoCompatibilityRequest;
use App\Http\Resources\StoryVideoGenerationJobResource;
use App\Story\Contracts\StoryVideoEngineInterface;
use App\Story\Models\StoryVideoGenerationJob;
use App\Story\Services\StoryVideoDispatchService;
use App\Story\Enums\StoryVideoCapability;
use App\Story\Enums\StoryVideoInputType;
use App\Story\Models\StoryWorkspace;
use App\Story\Video\StoryCapabilityRoute;
use App\Story\Video\StoryVideoGenerationRequest;
use App\Story\Video\StoryVideoInput;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

class StoryVideoEngineController extends Controller
{
    public function __construct(
        private StoryVideoEngineInterface $engine,
        private ProjectServiceInterface $projects,
        private StoryVideoDispatchService $dispatch,
    ) {}

    public function capabilities(): JsonResponse
    {
        $this->authorize('viewAny', StoryWorkspace::class);

        return ApiResponse::success(array_map(
            static fn (StoryCapabilityRoute $route): array => $route->toArray(),
            $this->engine->catalog(),
        ));
    }

    public function providers(): JsonResponse
    {
        $this->authorize('viewAny', StoryWorkspace::class);

        return ApiResponse::success($this->engine->providers());
    }

    public function validateCompatibility(
        ValidateStoryVideoCompatibilityRequest $request,
        string $uuid,
    ): JsonResponse {
        $project = $this->projects->getByUuid($uuid);
        $this->authorize('select', [StoryWorkspace::class, $project]);

        $result = $this->engine->validateCompatibility(
            $project,
            $this->toGenerationRequest($request->validated()),
        );

        return ApiResponse::success($result->toArray());
    }

    public function prepareJob(PrepareStoryVideoJobRequest $request, string $uuid): JsonResponse
    {
        $project = $this->projects->getByUuid($uuid);
        $this->authorize('select', [StoryWorkspace::class, $project]);

        $result = $this->engine->prepareJob(
            $project,
            $this->toGenerationRequest($request->validated()),
        );

        return ApiResponse::success([
            'created' => $result['created'],
            'job' => (new StoryVideoGenerationJobResource($result['job']))->resolve(),
        ], $result['created'] ? 201 : 200);
    }

    public function generate(PrepareStoryVideoJobRequest $request, string $uuid): JsonResponse
    {
        $project = $this->projects->getByUuid($uuid);
        $this->authorize('select', [StoryWorkspace::class, $project]);

        $result = $this->dispatch->start($project, $this->toGenerationRequest($request->validated()));

        return ApiResponse::success([
            'created' => $result['created'],
            'units' => $result['units'],
            'job' => (new StoryVideoGenerationJobResource($result['job']))->resolve(),
            'output_url' => null,
        ], $result['created'] ? 201 : 200);
    }

    public function showJob(string $uuid, string $jobUuid): JsonResponse
    {
        $project = $this->projects->getByUuid($uuid);
        $this->authorize('select', [StoryWorkspace::class, $project]);
        $job = $this->findJob($project->id, $jobUuid);
        $job = $this->dispatch->refresh($project, $job);

        return ApiResponse::success([
            'job' => (new StoryVideoGenerationJobResource($job))->resolve(),
            'output_url' => null,
        ]);
    }

    public function file(string $uuid, string $jobUuid): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        $project = $this->projects->getByUuid($uuid);
        $this->authorize('select', [StoryWorkspace::class, $project]);

        return $this->dispatch->file($project, $this->findJob($project->id, $jobUuid));
    }

    private function findJob(int $projectId, string $jobUuid): StoryVideoGenerationJob
    {
        $job = StoryVideoGenerationJob::query()
            ->where('uuid', $jobUuid)
            ->whereHas('workspace', static function ($query) use ($projectId): void {
                $query->where('project_id', $projectId);
            })
            ->first();

        if ($job === null) {
            abort(404);
        }

        return $job;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function toGenerationRequest(array $payload): StoryVideoGenerationRequest
    {
        $capability = StoryVideoCapability::from((string) $payload['capability']);
        $inputs = [];
        foreach ($payload['inputs'] ?? [] as $index => $row) {
            if (! is_array($row) || ! isset($row['type'])) {
                continue;
            }
            $type = StoryVideoInputType::tryFrom((string) $row['type']);
            if ($type === null) {
                continue;
            }
            $inputs[] = new StoryVideoInput(
                type: $type,
                assetId: isset($row['asset_id']) ? (string) $row['asset_id'] : null,
                role: isset($row['role']) ? (string) $row['role'] : null,
                order: (int) ($row['order'] ?? $index),
            );
        }

        return new StoryVideoGenerationRequest(
            capability: $capability,
            reelUuid: isset($payload['reel_id']) ? (string) $payload['reel_id'] : null,
            sceneUuid: isset($payload['scene_id']) ? (string) $payload['scene_id'] : null,
            prompt: isset($payload['prompt']) ? (string) $payload['prompt'] : null,
            durationSeconds: isset($payload['duration_seconds']) ? (int) $payload['duration_seconds'] : null,
            aspectRatio: isset($payload['aspect_ratio']) ? (string) $payload['aspect_ratio'] : null,
            resolution: isset($payload['resolution']) ? (string) $payload['resolution'] : null,
            audioRequested: (bool) ($payload['audio_requested'] ?? false),
            inputs: $inputs,
            preferredProvider: isset($payload['preferred_provider']) ? (string) $payload['preferred_provider'] : null,
            preferredModel: isset($payload['preferred_model']) ? (string) $payload['preferred_model'] : null,
            idempotencyKey: isset($payload['idempotency_key']) ? (string) $payload['idempotency_key'] : null,
        );
    }
}
