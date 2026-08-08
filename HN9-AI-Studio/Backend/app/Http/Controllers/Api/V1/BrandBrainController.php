<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Contracts\Services\ProjectServiceInterface;
use App\Contracts\Services\PromptRuntime\BrandContextServiceInterface;
use App\DTOs\Project\UpdateProjectData;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreBrandInsightRequest;
use App\Http\Requests\UpdateProjectRequest;
use App\Models\Project;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class BrandBrainController extends Controller
{
    public function __construct(
        private BrandContextServiceInterface $brandContext,
        private ProjectServiceInterface $projects,
    ) {}

    public function show(Request $request): JsonResponse
    {
        $project = $this->resolveProject($request);

        $this->authorize('view', $project);

        $context = $this->brandContext->forProject($project);

        return ApiResponse::success($context);
    }

    public function update(UpdateProjectRequest $request): JsonResponse
    {
        $project = $this->resolveProject($request);

        $this->authorize('update', $project);

        // Use the existing UpdateProjectRequest rules; only settings will be
        // provided by the Brand Brain update flow.
        $updated = $this->projects->update($project, UpdateProjectData::fromArray($request->validated()), $request->user());

        return ApiResponse::success($updated);
    }

    public function insights(StoreBrandInsightRequest $request, string $projectUuid): JsonResponse
    {
        $project = $this->projects->getByUuid($projectUuid);

        $this->authorize('view', $project);

        $context = $this->brandContext->forProject($project, ['include' => ['metadata', 'audience']]);

        // Lightweight insights: echo a few brand context keys as the insight payload.
        $insights = [
            'brand_name' => $context['brand_name'] ?? null,
            'audience' => $context['audience'] ?? [],
            'tone' => $context['writing_tone'] ?? null,
        ];

        return ApiResponse::created(['insights' => $insights]);
    }

    private function resolveProject(Request $request): Project
    {
        $uuid = $request->query('project');

        if ($uuid) {
            return $this->projects->getByUuid((string) $uuid);
        }

        $user = $request->user();

        return $user->projects()->firstOrFail();
    }
}
