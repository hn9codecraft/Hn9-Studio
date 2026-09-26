<?php

declare(strict_types=1);

namespace App\Story\Services;

use App\AI\Contracts\ProviderDispatcherInterface;
use App\AI\Exceptions\ProviderException;
use App\AI\Execution\DispatchOptions;
use App\AI\Requests\TextRequest;
use App\AI\Responses\TextResponse;
use App\Models\Project;
use App\Story\Contracts\StoryPlannerServiceInterface;
use App\Story\Contracts\StoryPlanRepositoryInterface;
use App\Story\Contracts\StoryPlanVersionRepositoryInterface;
use App\Story\Contracts\StoryWorkspaceServiceInterface;
use App\Story\Enums\StoryPlanStatus;
use App\Story\Enums\StoryPlanVersionStatus;
use App\Story\Exceptions\StoryException;
use App\Story\Exceptions\StoryPlannerException;
use App\Story\Models\StoryPlan;
use App\Story\Models\StoryPlanVersion;
use App\Story\Support\StoryPlanDurationCalculator;
use App\Story\Support\StoryPlannerContextAssembler;
use App\Story\Support\StoryPlannerPromptBuilder;
use App\Story\Support\StoryPlanStructuredOutput;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Story Planner orchestration. Text generation goes through ProviderDispatcherInterface only.
 */
final readonly class StoryPlannerService implements StoryPlannerServiceInterface
{
    public function __construct(
        private StoryWorkspaceServiceInterface $workspaces,
        private StoryPlanRepositoryInterface $plans,
        private StoryPlanVersionRepositoryInterface $versions,
        private StoryPlanDurationCalculator $durations,
        private StoryPlannerContextAssembler $context,
        private StoryPlannerPromptBuilder $prompts,
        private StoryPlanStructuredOutput $structured,
        private ProviderDispatcherInterface $dispatcher,
    ) {}

    public function listForProject(Project $project): Collection
    {
        $workspace = $this->workspaces->workspaceForProject($project);

        return $this->plans->listForWorkspace($workspace);
    }

    public function getForProject(Project $project, string $planUuid): StoryPlan
    {
        $workspace = $this->workspaces->workspaceForProject($project);
        $plan = $this->plans->findByUuidForWorkspace($workspace, $planUuid);

        if ($plan === null) {
            throw StoryException::notFound('Story plan');
        }

        return $plan;
    }

    public function create(Project $project, array $attributes): StoryPlan
    {
        $duration = (int) ($attributes['requested_duration_seconds'] ?? 0);
        $this->durations->calculate($duration);

        $workspace = $this->workspaces->workspaceForProject($project);

        return $this->plans->create($workspace, [
            'title' => $attributes['title'] ?? null,
            'idea' => $attributes['idea'],
            'requested_duration_seconds' => $duration,
        ]);
    }

    public function generate(Project $project, string $planUuid, array $options = []): StoryPlan
    {
        $plan = $this->getForProject($project, $planUuid);

        if (! $plan->statusEnum()->allowsGenerate()) {
            throw StoryPlannerException::notGeneratable($plan->status);
        }

        return $this->runGeneration($project, $plan, null, $options);
    }

    public function regenerate(
        Project $project,
        string $planUuid,
        ?string $instruction = null,
        array $options = [],
    ): StoryPlan {
        $plan = $this->getForProject($project, $planUuid);

        if (! $plan->statusEnum()->allowsGenerate()) {
            throw StoryPlannerException::notGeneratable($plan->status);
        }

        return $this->runGeneration($project, $plan, $instruction, $options);
    }

    public function versionsForProject(Project $project, string $planUuid): Collection
    {
        $plan = $this->getForProject($project, $planUuid);

        return $this->versions->listForPlan($plan);
    }

    /**
     * @param  array<string, mixed>  $options
     */
    private function runGeneration(
        Project $project,
        StoryPlan $plan,
        ?string $instruction,
        array $options,
    ): StoryPlan {
        $durationPlan = $this->durations->calculate($plan->requested_duration_seconds);
        $previous = $plan->currentVersion;
        $assembled = $this->context->assemble($project, $plan, $previous);
        $promptParts = $this->prompts->build($assembled, $durationPlan, $instruction);

        $version = $this->versions->create($plan, [
            'version' => $this->versions->nextVersion($plan),
            'status' => StoryPlanVersionStatus::Generating->value,
            'instruction' => $instruction,
            'input' => [
                'context' => $assembled,
                'duration' => $durationPlan,
                'system' => $promptParts['system'],
            ],
            'remainder_strategy' => $durationPlan['remainder_strategy'],
        ]);

        $this->plans->update($plan, [
            'status' => StoryPlanStatus::Generating->value,
        ]);

        $provider = is_string($options['provider'] ?? null) ? $options['provider'] : null;
        $model = is_string($options['model'] ?? null) ? $options['model'] : null;

        $request = new TextRequest(
            prompt: $promptParts['prompt'],
            model: $model,
            system: $promptParts['system'],
            temperature: 0.4,
            maxTokens: 8000,
        );

        $dispatchOptions = $provider !== null && $provider !== ''
            ? DispatchOptions::only($provider)
            : null;

        try {
            $result = $this->dispatcher->dispatch($request, $dispatchOptions);
            $response = $result->response;

            if (! $response instanceof TextResponse) {
                throw StoryPlannerException::generationFailed('Text generation returned an unexpected response.');
            }

            $normalized = $this->structured->parseAndValidate(
                $response->text,
                $durationPlan['total_duration_seconds'],
                $durationPlan['segments'],
            );

            return DB::transaction(function () use ($plan, $version, $normalized, $result, $response, $model, $durationPlan): StoryPlan {
                $completed = $this->versions->update($version, [
                    'status' => StoryPlanVersionStatus::Completed->value,
                    'master_story' => $normalized['master_story'],
                    'plan' => $normalized,
                    'remainder_strategy' => $normalized['remainder_strategy'] ?? $durationPlan['remainder_strategy'],
                    'provider' => $result->providerKey,
                    'model' => $response->model ?? $model,
                    'generation' => [
                        'provider' => $result->providerKey,
                        'model' => $response->model ?? $model,
                        'usage' => $response->usage?->toArray(),
                        'duration_ms' => $result->durationMs,
                        'estimated_cost' => $result->estimatedCost,
                    ],
                    'error_message' => null,
                ]);

                return $this->plans->update($plan, [
                    'status' => StoryPlanStatus::Completed->value,
                    'title' => $plan->title ?: $normalized['title'],
                    'current_version_id' => $completed->id,
                ]);
            });
        } catch (StoryPlannerException $exception) {
            $this->markFailed($plan, $version, $exception->getMessage());
            throw $exception;
        } catch (ProviderException $exception) {
            $message = $exception->getMessage();
            $this->markFailed($plan, $version, $message);
            throw StoryPlannerException::generationFailed($message);
        } catch (Throwable $exception) {
            $message = $exception->getMessage() !== '' ? $exception->getMessage() : 'Story planning failed.';
            $this->markFailed($plan, $version, $message);
            throw StoryPlannerException::generationFailed($message);
        }
    }

    private function markFailed(StoryPlan $plan, StoryPlanVersion $version, string $message): void
    {
        $this->versions->update($version, [
            'status' => StoryPlanVersionStatus::Failed->value,
            'error_message' => $message,
            'plan' => null,
            'master_story' => null,
        ]);

        $this->plans->update($plan, [
            'status' => StoryPlanStatus::Failed->value,
        ]);
    }
}
