<?php

declare(strict_types=1);

namespace App\Story\Services;

use App\Jobs\ProcessStoryVideoJob;
use App\Models\Project;
use App\Story\Contracts\LiveStoryVideoProviderAdapterInterface;
use App\Story\Contracts\StoryCapabilityRouterInterface;
use App\Story\Contracts\StoryVideoEngineInterface;
use App\Story\Enums\StoryVideoCapability;
use App\Story\Enums\StoryVideoErrorCode;
use App\Story\Enums\StoryVideoJobStatus;
use App\Story\Exceptions\StoryVideoEngineException;
use App\Story\Media\StoryMediaToolkit;
use App\Story\Models\StoryGenerationAttempt;
use App\Story\Models\StoryVideoGenerationJob;
use App\Story\Video\StoryVideoAssetResolver;
use App\Story\Video\StoryVideoGenerationRequest;
use App\Story\Video\StoryVideoUnitPlanner;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Submits Story video jobs through the capability router, inline or on the
 * queue. Only credentialed adapters can start work; the highest priority one
 * that supports the request serves it. Vendor names are not used here.
 */
final readonly class StoryVideoDispatchService
{
    /**
     * Key of the original credentialed adapter; kept for stored jobs.
     */
    public const LIVE_PROVIDER_KEY = 'video.live';

    public function __construct(
        private StoryVideoEngineInterface $engine,
        private StoryCapabilityRouterInterface $router,
        private StoryVideoUnitPlanner $units,
        private StoryVideoAssetResolver $assets,
        private StoryVideoJobRunner $runner,
        private StoryGenerationAttemptRecorder $attempts,
        private StoryMediaToolkit $media,
    ) {}

    /**
     * Throws generationNotEnabled, after recording the attempt for History, when no live
     * adapter serves the capability.
     */
    public function assertLive(
        Project $project,
        StoryVideoCapability $capability,
        ?string $reelUuid = null,
        ?string $sceneUuid = null,
    ): void {
        if ($this->liveSupports($capability)) {
            return;
        }

        $this->attempts->record(
            $project,
            $capability === StoryVideoCapability::Audio
                ? StoryGenerationAttemptRecorder::KIND_AUDIO
                : StoryGenerationAttemptRecorder::KIND_VIDEO,
            StoryGenerationAttempt::STATUS_NOT_CONNECTED,
            $capability->value,
            'provider_not_connected',
            null,
            $reelUuid,
            $sceneUuid,
        );

        throw StoryVideoEngineException::generationNotEnabled();
    }

    public function liveSupports(StoryVideoCapability $capability): bool
    {
        return $this->liveAdapterFor($capability) !== null;
    }

    /**
     * @return list<LiveStoryVideoProviderAdapterInterface>
     */
    public function liveAdapters(): array
    {
        $live = array_values(array_filter(
            $this->router->adapters(),
            static fn ($adapter): bool => $adapter instanceof LiveStoryVideoProviderAdapterInterface && $adapter->enabled(),
        ));
        usort($live, static fn ($a, $b): int => $b->priority() <=> $a->priority());

        return $live;
    }

    /**
     * @return list<string>
     */
    public function liveKeys(): array
    {
        return array_map(static fn ($adapter): string => $adapter->key(), $this->liveAdapters());
    }

    public function liveAdapterFor(StoryVideoCapability $capability, ?string $preferred = null): ?LiveStoryVideoProviderAdapterInterface
    {
        $candidates = array_values(array_filter(
            $this->liveAdapters(),
            static fn ($adapter): bool => $adapter->supports($capability) && $adapter->isAvailable($capability),
        ));
        if ($preferred !== null) {
            foreach ($candidates as $candidate) {
                if ($candidate->key() === $preferred) {
                    return $candidate;
                }
            }
        }

        return $candidates[0] ?? null;
    }

    /**
     * @return list<string>
     */
    public function liveAudioRoles(): array
    {
        return $this->liveAdapterFor(StoryVideoCapability::Audio)?->supportedAudioRoles(StoryVideoCapability::Audio) ?? [];
    }

    /**
     * @return array{job: StoryVideoGenerationJob, created: bool, units: list<int>}
     */
    public function start(Project $project, StoryVideoGenerationRequest $request): array
    {
        $this->assertLive($project, $request->capability, $request->reelUuid, $request->sceneUuid);

        $adapter = $this->liveAdapterFor($request->capability, $request->preferredProvider);
        if (! $adapter instanceof LiveStoryVideoProviderAdapterInterface) {
            throw StoryVideoEngineException::generationNotEnabled();
        }

        $request = $this->assets->resolve($project, $request);
        $requested = (int) ($request->durationSeconds ?? $adapter->maxDurationSeconds($request->capability) ?? 8);
        $planned = $this->units->units($requested, $adapter->supportedDurations($request->capability));
        $multi = count($planned) > 1;

        if ($multi && $this->existingJob($project, $request) === null) {
            if (! in_array($request->capability, [
                StoryVideoCapability::TextToVideo,
                StoryVideoCapability::ImageToVideo,
                StoryVideoCapability::ReferenceToVideo,
            ], true)) {
                throw StoryVideoEngineException::invalidInput('This change can only be made to one clip at a time. Use a shorter length.');
            }
            if (! $this->media->available()) {
                throw StoryVideoEngineException::invalidInput(
                    'This scene needs '.count($planned).' clips joined together, and the video builder is not set up on this server yet.',
                );
            }
        }

        $jobs = [];
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
                prompt: $multi ? $this->unitPrompt((string) $request->prompt, $index, count($planned)) : $request->prompt,
                negativePrompt: $request->negativePrompt,
                durationSeconds: $seconds,
                aspectRatio: $request->aspectRatio,
                resolution: $request->resolution,
                audioRequested: $request->audioRequested,
                inputs: $request->inputs,
                preferredProvider: $adapter->key(),
                preferredModel: $request->preferredModel,
                idempotencyKey: $key,
                metadata: array_merge($request->metadata, [
                    'unit_index' => $index + 1,
                    'unit_count' => count($planned),
                ]),
            );

            $prepared = $this->engine->prepareJob($project, $unitRequest);
            $job = $prepared['job'];
            if ($prepared['created'] && $job->provider_key !== $adapter->key()) {
                $job->forceFill([
                    'status' => StoryVideoJobStatus::Failed->value,
                    'error_code' => StoryVideoErrorCode::CapabilityNotAvailable->value,
                    'error_message' => 'The connected video service cannot make a video with these settings.',
                    'failed_at' => now(),
                ])->save();

                throw StoryVideoEngineException::capabilityNotAvailable('The connected video service cannot make a video with these settings.');
            }
            if ($index === 0) {
                $created = (bool) $prepared['created'];
            }
            $jobs[] = ['job' => $job, 'created' => (bool) $prepared['created'], 'request' => $unitRequest];
        }

        $first = $jobs[0]['job'];
        if ($multi && $jobs[0]['created']) {
            $this->linkUnits($first, array_map(static fn (array $row): StoryVideoGenerationJob => $row['job'], $jobs), $planned, $requested);
        }

        if ($jobs[0]['created'] && ! $first->operation_id) {
            if ($this->runner->queued()) {
                ProcessStoryVideoJob::dispatch($first->id);
            } else {
                $this->runner->submit($first, $jobs[0]['request']);
            }
        }

        return ['job' => $first->fresh() ?? $first, 'created' => $created, 'units' => $planned];
    }

    public function refresh(Project $project, StoryVideoGenerationJob $job): StoryVideoGenerationJob
    {
        $this->assertOwns($project, $job);

        return $this->runner->advance($job);
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
        if (! in_array($disk, ['videos', 'voice'], true) || str_contains($path, '..') || ! Storage::disk($disk)->exists($path)) {
            throw StoryVideoEngineException::invalidInput('The stored video file is missing.');
        }

        return Storage::disk($disk)->response($path, basename($path), [
            'Content-Type' => (string) ($storage['mime'] ?? 'video/mp4'),
        ]);
    }

    /**
     * @param  list<StoryVideoGenerationJob>  $jobs
     * @param  list<int>  $planned
     */
    private function linkUnits(StoryVideoGenerationJob $first, array $jobs, array $planned, int $requested): void
    {
        $uuids = array_map(static fn (StoryVideoGenerationJob $job): string => $job->uuid, $jobs);
        foreach ($jobs as $index => $job) {
            $metadata = (array) $job->provider_metadata;
            $metadata['unit_index'] = $index + 1;
            $metadata['unit_count'] = count($jobs);
            if ($index === 0) {
                $metadata['unit_jobs'] = $uuids;
                $metadata['unit_seconds'] = $planned;
                $metadata['target_seconds'] = $requested;
            } else {
                $metadata['primary_job'] = $first->uuid;
            }
            $job->forceFill(['provider_metadata' => $metadata])->save();
        }
    }

    private function unitPrompt(string $prompt, int $index, int $count): string
    {
        $prompt = trim($prompt);
        if ($index === 0) {
            return $prompt.' (Part 1 of '.$count.': establish the scene.)';
        }

        return $prompt.' (Part '.($index + 1).' of '.$count.': continue directly from the previous part with the same characters, setting and look.)';
    }

    private function existingJob(Project $project, StoryVideoGenerationRequest $request): ?StoryVideoGenerationJob
    {
        if ($request->idempotencyKey === null || $request->idempotencyKey === '') {
            return null;
        }

        return StoryVideoGenerationJob::query()
            ->where('idempotency_key', $request->idempotencyKey)
            ->whereHas('workspace', static function ($query) use ($project): void {
                $query->where('project_id', $project->id);
            })
            ->first();
    }

    private function assertOwns(Project $project, StoryVideoGenerationJob $job): void
    {
        $job->loadMissing('workspace');
        if ($job->workspace === null || (int) $job->workspace->project_id !== (int) $project->id) {
            throw StoryVideoEngineException::invalidInput('The video job does not belong to this project.');
        }
    }
}
