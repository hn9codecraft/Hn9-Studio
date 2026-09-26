<?php

declare(strict_types=1);

namespace App\Story\Services;

use App\Models\Project;
use App\Story\Contracts\StoryCapabilityRouterInterface;
use App\Story\Contracts\StoryVideoEngineInterface;
use App\Story\Contracts\StoryVideoProviderAdapterInterface;
use App\Story\Enums\StoryVideoCapability;
use App\Story\Enums\StoryVideoJobStatus;
use App\Story\Exceptions\StoryVideoEngineException;
use App\Story\Models\StoryVideoGenerationJob;
use App\Story\Video\StoryVideoAssetResolver;
use App\Story\Video\StoryVideoGenerationRequest;
use App\Story\Video\StoryVideoUnitPlanner;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Submits Story video jobs through the capability router.
 * Vendor names are not used here.
 */
final readonly class StoryVideoDispatchService
{
    public const LIVE_PROVIDER_KEY = 'video.live';

    public function __construct(
        private StoryVideoEngineInterface $engine,
        private StoryCapabilityRouterInterface $router,
        private StoryVideoUnitPlanner $units,
        private StoryVideoAssetResolver $assets,
    ) {}

    public function liveSupports(StoryVideoCapability $capability): bool
    {
        foreach ($this->router->adapters() as $candidate) {
            if ($candidate->key() === self::LIVE_PROVIDER_KEY && $candidate->supports($capability)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{job: StoryVideoGenerationJob, created: bool, units: list<int>}
     */
    public function start(Project $project, StoryVideoGenerationRequest $request): array
    {
        $adapter = null;
        foreach ($this->router->adapters() as $candidate) {
            if ($candidate->key() === self::LIVE_PROVIDER_KEY && $candidate->supports($request->capability)) {
                $adapter = $candidate;
                break;
            }
        }

        if (! $adapter instanceof StoryVideoProviderAdapterInterface) {
            throw StoryVideoEngineException::generationNotEnabled();
        }

        $request = $this->assets->resolve($project, $request);

        $planned = $this->units->units(
            (int) ($request->durationSeconds ?? $adapter->maxDurationSeconds($request->capability) ?? 8),
            $adapter->supportedDurations($request->capability),
        );

        $first = null;
        $created = false;
        foreach ($planned as $index => $seconds) {
            $key = $request->idempotencyKey;
            if ($key !== null && $key !== '' && $index > 0) {
                $key .= ':unit-'.($index + 1);
            }
            $unitRequest = new StoryVideoGenerationRequest(
                capability: $request->capability,
                workspaceUuid: $request->workspaceUuid,
                reelUuid: $request->reelUuid,
                sceneUuid: $request->sceneUuid,
                prompt: $request->prompt,
                negativePrompt: $request->negativePrompt,
                durationSeconds: $seconds,
                aspectRatio: $request->aspectRatio,
                resolution: $request->resolution,
                audioRequested: $request->audioRequested,
                inputs: $request->inputs,
                preferredProvider: self::LIVE_PROVIDER_KEY,
                preferredModel: $request->preferredModel,
                idempotencyKey: $key,
                metadata: array_merge($request->metadata, [
                    'unit_index' => $index + 1,
                    'unit_count' => count($planned),
                ]),
            );

            $prepared = $this->engine->prepareJob($project, $unitRequest);
            $job = $prepared['job'];
            if ($index === 0) {
                $first = $job;
                $created = (bool) $prepared['created'];
            }

            if (! $prepared['created'] || $job->operation_id) {
                continue;
            }

            if ($index > 0) {
                continue;
            }

            try {
                $submission = $adapter->submit($unitRequest);
            } catch (StoryVideoEngineException $exception) {
                $job->forceFill([
                    'status' => StoryVideoJobStatus::Failed->value,
                    'error_code' => $exception->errorCode(),
                    'error_message' => $exception->getMessage(),
                    'failed_at' => now(),
                ])->save();
                throw $exception;
            }

            $job->forceFill([
                'operation_id' => $submission->operationId,
                'model_key' => $submission->modelKey ?? $job->model_key,
                'status' => $submission->status->value,
                'request_payload' => $unitRequest->toArray(),
                'submitted_at' => now(),
            ])->save();
        }

        if (! $first instanceof StoryVideoGenerationJob) {
            throw StoryVideoEngineException::capabilityNotAvailable();
        }

        return ['job' => $first->fresh() ?? $first, 'created' => $created, 'units' => $planned];
    }

    public function refresh(Project $project, StoryVideoGenerationJob $job): StoryVideoGenerationJob
    {
        $this->assertOwns($project, $job);
        $adapter = $this->liveAdapter();
        if ($adapter === null || $job->provider_key !== self::LIVE_PROVIDER_KEY) {
            return $job;
        }

        $status = $adapter->status($job);
        $job->refresh();
        if ($status === StoryVideoJobStatus::Processing && ($job->provider_metadata['download_pending'] ?? false) === true) {
            $adapter->result($job);
            $job->refresh();
        }

        return $job;
    }

    public function file(Project $project, StoryVideoGenerationJob $job): StreamedResponse
    {
        $this->assertOwns($project, $job);
        $storage = $job->provider_metadata['storage'] ?? null;
        if (! is_array($storage) || ! isset($storage['disk'], $storage['path'])) {
            throw StoryVideoEngineException::invalidInput('No private video file is stored for this job.');
        }

        $disk = (string) $storage['disk'];
        $path = (string) $storage['path'];
        if (! Storage::disk($disk)->exists($path)) {
            throw StoryVideoEngineException::invalidInput('The stored video file is missing.');
        }

        return Storage::disk($disk)->response($path, basename($path), [
            'Content-Type' => (string) ($storage['mime'] ?? 'video/mp4'),
        ]);
    }

    private function liveAdapter(): ?StoryVideoProviderAdapterInterface
    {
        foreach ($this->router->adapters() as $candidate) {
            if ($candidate->key() === self::LIVE_PROVIDER_KEY) {
                return $candidate;
            }
        }

        return null;
    }

    private function assertOwns(Project $project, StoryVideoGenerationJob $job): void
    {
        $job->loadMissing('workspace');
        if ($job->workspace === null || (int) $job->workspace->project_id !== (int) $project->id) {
            throw StoryVideoEngineException::invalidInput('The video job does not belong to this project.');
        }
    }
}
