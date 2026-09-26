<?php

declare(strict_types=1);

namespace App\Story\Video;

use App\Models\Project;
use App\Story\Contracts\StoryCapabilityRouterInterface;
use App\Story\Contracts\StoryReelRepositoryInterface;
use App\Story\Contracts\StorySceneRepositoryInterface;
use App\Story\Contracts\StoryVideoEngineInterface;
use App\Story\Contracts\StoryVideoProviderAdapterInterface;
use App\Story\Contracts\StoryWorkspaceRepositoryInterface;
use App\Story\Enums\StoryVideoCapability;
use App\Story\Enums\StoryVideoJobStatus;
use App\Story\Exceptions\StoryException;
use App\Story\Exceptions\StoryVideoEngineException;
use App\Story\Models\StoryVideoGenerationJob;
use Illuminate\Support\Facades\DB;

/**
 * Production Story Video Engine foundation.
 * Routes, validates, and tracks jobs — never opens a provider socket in M11.6.
 */
final readonly class StoryVideoEngine implements StoryVideoEngineInterface
{
    public function __construct(
        private StoryCapabilityRouterInterface $router,
        private StoryWorkspaceRepositoryInterface $workspaces,
        private StoryReelRepositoryInterface $reels,
        private StorySceneRepositoryInterface $scenes,
        private StoryVideoTimeoutPolicy $timeouts,
    ) {}

    public function catalog(): array
    {
        return $this->router->catalog();
    }

    public function providers(): array
    {
        $out = [];
        foreach ($this->router->adapters() as $adapter) {
            $out[] = $this->providerPublicMeta($adapter);
        }

        usort($out, static fn (array $a, array $b): int => ($b['priority'] ?? 0) <=> ($a['priority'] ?? 0));

        return $out;
    }

    public function route(StoryVideoCapability $capability): StoryCapabilityRoute
    {
        return $this->router->route($capability);
    }

    public function resolve(StoryVideoGenerationRequest $request): StoryVideoRoutingDecision
    {
        return $this->router->resolve($request);
    }

    public function validateCompatibility(
        Project $project,
        StoryVideoGenerationRequest $request,
    ): StoryVideoCompatibilityResult {
        $workspace = $this->workspaces->firstOrCreateForProject($project)->loadMissing('project');
        $issues = [];
        $checks = [
            'capability_known' => true,
            'duration_ok' => true,
            'aspect_ratio_ok' => true,
            'input_ok' => true,
            'audio_ok' => true,
            'scene_owned' => true,
        ];

        if ($request->sceneUuid !== null) {
            if ($request->reelUuid === null) {
                $issues[] = 'scene_requires_reel';
                $checks['scene_owned'] = false;
            } else {
                $reel = $this->reels->findByUuidForWorkspace($workspace, $request->reelUuid);
                if ($reel === null) {
                    $issues[] = 'reel_not_found';
                    $checks['scene_owned'] = false;
                } else {
                    $scene = $this->scenes->findByUuidForReel($reel, $request->sceneUuid);
                    if ($scene === null) {
                        $issues[] = 'scene_not_found';
                        $checks['scene_owned'] = false;
                    } elseif ($request->durationSeconds === null && $scene->duration_seconds) {
                        $request = new StoryVideoGenerationRequest(
                            capability: $request->capability,
                            workspaceUuid: $workspace->uuid,
                            reelUuid: $request->reelUuid,
                            sceneUuid: $request->sceneUuid,
                            prompt: $request->prompt ?? $scene->visual_prompt,
                            negativePrompt: $request->negativePrompt,
                            durationSeconds: (int) $scene->duration_seconds,
                            aspectRatio: $request->aspectRatio,
                            resolution: $request->resolution,
                            audioRequested: $request->audioRequested,
                            inputs: $request->inputs,
                            preferredProvider: $request->preferredProvider,
                            preferredModel: $request->preferredModel,
                            idempotencyKey: $request->idempotencyKey,
                            metadata: $request->metadata,
                            extensions: $request->extensions,
                        );
                    }
                }
            }
        }

        $catalog = $this->router->route($request->capability);
        if ($catalog->adapterKeys === []) {
            $issues[] = 'capability_unsupported';
            $checks['capability_known'] = false;
        }

        if ($request->durationSeconds !== null) {
            $durations = $catalog->durations;
            $min = $catalog->minDurationSeconds;
            $max = $catalog->maxDurationSeconds;
            $seconds = $request->durationSeconds;
            $ok = ($durations !== [] && in_array($seconds, $durations, true))
                || ($durations === [] && ($min === null || $seconds >= $min) && ($max === null || $seconds <= $max));
            if (! $ok && ($durations !== [] || $min !== null || $max !== null)) {
                $issues[] = 'duration_unsupported';
                $checks['duration_ok'] = false;
            }
        }

        if ($request->aspectRatio !== null
            && $catalog->aspectRatios !== []
            && ! in_array($request->aspectRatio, $catalog->aspectRatios, true)
        ) {
            $issues[] = 'aspect_ratio_unsupported';
            $checks['aspect_ratio_ok'] = false;
        }

        if ($request->audioRequested && ! $catalog->audioSupported) {
            $issues[] = 'audio_unsupported';
            $checks['audio_ok'] = false;
        }

        if ($request->inputs !== []) {
            foreach ($request->inputs as $input) {
                if ($catalog->inputTypes !== []
                    && ! in_array($input->type->value, $catalog->inputTypes, true)
                ) {
                    $issues[] = 'input_type_unsupported';
                    $checks['input_ok'] = false;
                    break;
                }
            }
        }

        $routing = $this->router->resolve($request);
        if (! $routing->matched) {
            $issues[] = 'no_eligible_provider';
        }

        return new StoryVideoCompatibilityResult(
            capability: $request->capability,
            compatible: $issues === [] && $routing->matched,
            issues: $issues,
            checks: $checks,
            routing: $routing,
        );
    }

    public function prepareJob(Project $project, StoryVideoGenerationRequest $request): array
    {
        $workspace = $this->workspaces->firstOrCreateForProject($project)->loadMissing('project');

        if ($request->idempotencyKey !== null && $request->idempotencyKey !== '') {
            $existing = StoryVideoGenerationJob::query()
                ->where('story_workspace_id', $workspace->id)
                ->where('idempotency_key', $request->idempotencyKey)
                ->first();

            if ($existing !== null) {
                return ['job' => $existing, 'created' => false];
            }
        }

        $compatibility = $this->validateCompatibility($project, $request);
        if (! $compatibility->compatible || $compatibility->routing === null || ! $compatibility->routing->matched) {
            throw StoryVideoEngineException::capabilityNotAvailable(
                $compatibility->routing?->errorMessage
                    ?? 'No eligible video provider can satisfy this request.',
            );
        }

        $routing = $compatibility->routing;
        $reelId = null;
        $sceneId = null;

        if ($request->reelUuid !== null) {
            $reel = $this->reels->findByUuidForWorkspace($workspace, $request->reelUuid);
            if ($reel === null) {
                throw StoryException::notFound('Reel');
            }
            $reelId = $reel->id;
            if ($request->sceneUuid !== null) {
                $scene = $this->scenes->findByUuidForReel($reel, $request->sceneUuid);
                if ($scene === null) {
                    throw StoryException::notFound('Scene');
                }
                $sceneId = $scene->id;
            }
        }

        $job = DB::transaction(function () use ($workspace, $request, $routing, $reelId, $sceneId) {
            return StoryVideoGenerationJob::query()->create([
                'story_workspace_id' => $workspace->id,
                'story_reel_id' => $reelId,
                'story_scene_id' => $sceneId,
                'capability' => $request->capability->value,
                'provider_key' => $routing->providerKey,
                'model_key' => $routing->modelKey,
                'operation_id' => null,
                'status' => StoryVideoJobStatus::Queued->value,
                'async_mode' => $routing->asyncMode?->value,
                'idempotency_key' => $request->idempotencyKey,
                'request_payload' => $request->toArray(),
                'routing' => $routing->toArray(),
                'provider_metadata' => [
                    'timeout_policy' => $this->timeouts->toArray(),
                ],
                'retry_count' => 0,
                'timed_out' => false,
            ]);
        });

        return ['job' => $job, 'created' => true];
    }

    public function execute(StoryVideoCapability $capability, array $input = []): never
    {
        throw StoryVideoEngineException::generationNotEnabled();
    }

    /**
     * @return array<string, mixed>
     */
    private function providerPublicMeta(StoryVideoProviderAdapterInterface $adapter): array
    {
        $capabilities = [];
        foreach (StoryVideoCapability::cases() as $capability) {
            if (! $adapter->supports($capability)) {
                continue;
            }
            $capabilities[] = [
                'capability' => $capability->value,
                'label' => $capability->label(),
                'available' => $adapter->isAvailable($capability),
                'supported_durations' => $adapter->supportedDurations($capability),
                'min_duration_seconds' => $adapter->minDurationSeconds($capability),
                'max_duration_seconds' => $adapter->maxDurationSeconds($capability),
                'supported_aspect_ratios' => $adapter->supportedAspectRatios($capability),
                'supported_resolutions' => $adapter->supportedResolutions($capability),
                'supported_input_types' => $adapter->supportedInputTypes($capability),
                'audio_supported' => $adapter->audioSupported($capability),
                'async_mode' => $adapter->asyncMode($capability)->value,
                'polling_supported' => $adapter->supportsPolling($capability),
                'webhook_supported' => $adapter->supportsWebhook($capability),
                'download_supported' => $adapter->supportsDownload($capability),
            ];
        }

        return [
            'key' => $adapter->key(),
            'label' => $adapter->displayName(),
            'enabled' => $adapter->enabled(),
            'priority' => $adapter->priority(),
            'healthy' => $this->adapterIsHealthy($adapter),
            'capabilities' => $capabilities,
            'models' => array_map(
                static fn (StoryVideoModelSpec $model): array => $model->toPublicArray(),
                $adapter->models(),
            ),
        ];
    }

    private function adapterIsHealthy(StoryVideoProviderAdapterInterface $adapter): bool
    {
        foreach (StoryVideoCapability::cases() as $capability) {
            if ($adapter->isAvailable($capability)) {
                return true;
            }
        }

        return false;
    }
}
