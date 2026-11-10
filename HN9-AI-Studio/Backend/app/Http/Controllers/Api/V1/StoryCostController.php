<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Contracts\Logging\ActivityLoggerInterface;
use App\Contracts\Services\ProjectServiceInterface;
use App\Http\Controllers\Controller;
use App\Http\Requests\EstimateStoryCostRequest;
use App\Http\Requests\UpdateStoryBudgetRequest;
use App\Models\User;
use App\Story\Contracts\StoryWorkspaceServiceInterface;
use App\Story\Models\StoryWorkspace;
use App\Story\Services\StoryCostEstimator;
use App\Story\Services\StorySpendService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

class StoryCostController extends Controller
{
    public function __construct(
        private ProjectServiceInterface $projects,
        private StoryCostEstimator $estimator,
        private StorySpendService $spend,
        private StoryWorkspaceServiceInterface $workspaces,
        private ActivityLoggerInterface $activity,
    ) {}

    public function estimate(EstimateStoryCostRequest $request, string $uuid): JsonResponse
    {
        $project = $this->projects->getByUuid($uuid);
        $this->authorize('select', [StoryWorkspace::class, $project]);
        $estimate = $this->estimator->estimate($project, (string) $request->validated('kind'), $request->validated());

        return ApiResponse::success($estimate->toArray());
    }

    public function summary(string $uuid): JsonResponse
    {
        $project = $this->projects->getByUuid($uuid);
        $this->authorize('select', [StoryWorkspace::class, $project]);

        return ApiResponse::success($this->spend->summary($project));
    }

    public function updateBudget(UpdateStoryBudgetRequest $request, string $uuid): JsonResponse
    {
        $project = $this->projects->getByUuid($uuid);
        $this->authorize('select', [StoryWorkspace::class, $project]);
        $workspace = $this->workspaces->workspaceForProject($project);
        $amount = $request->validated('budget_amount');
        $currency = strtoupper((string) ($request->validated('budget_currency') ?: $workspace->budget_currency ?: 'INR'));
        $workspace->forceFill([
            'budget_amount' => $amount === null || $amount === '' ? null : $amount,
            'budget_currency' => $currency,
        ])->save();

        $actor = $request->user();
        $this->activity->log(
            'story.budget.updated',
            $workspace,
            $actor instanceof User ? $actor : null,
            'Project budget updated',
            [
                'budget_amount' => $workspace->budget_amount,
                'budget_currency' => $workspace->budget_currency,
            ],
        );

        return ApiResponse::success($this->spend->summary($project));
    }
}
