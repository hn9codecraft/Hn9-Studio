<?php

declare(strict_types=1);

namespace App\Story\Services;

use App\Contracts\Logging\ActivityLoggerInterface;
use App\Jobs\ProcessStoryVideoJob;
use App\Models\Project;
use App\Models\User;
use App\Story\Contracts\LiveStoryVideoProviderAdapterInterface;
use App\Story\Contracts\StoryCapabilityRouterInterface;
use App\Story\Contracts\StoryProductionPlanServiceInterface;
use App\Story\Contracts\StoryProductionUnitGenerationServiceInterface;
use App\Story\Contracts\StoryVideoEngineInterface;
use App\Story\Enums\StorySceneStatus;
use App\Story\Enums\StoryVideoCapability;
use App\Story\Enums\StoryVideoErrorCode;
use App\Story\Enums\StoryVideoInputType;
use App\Story\Enums\StoryVideoJobStatus;
use App\Story\Exceptions\StoryException;
use App\Story\Exceptions\StoryVideoEngineException;
use App\Story\Media\StoryMediaException;
use App\Story\Media\StoryMediaToolkit;
use App\Story\Models\StoryProductionPlan;
use App\Story\Models\StoryProductionUnit;
use App\Story\Models\StoryScene;
use App\Story\Models\StoryVideoGenerationJob;
use App\Story\Support\StoryGenerationUnitCalculator;
use App\Story\Video\StoryVideoAssetResolver;
use App\Story\Video\StoryVideoGenerationRequest;
use App\Story\Video\StoryVideoInput;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * One Generation Unit, one normalized request, one generation job.
 *
 * The unit's own duration is the requested length. This service does not split
 * a scene, round a remainder up, or switch to another provider when the
 * connected one cannot make that length. Provider ranking belongs to M11.18.4.
 *
 * The same intent reuses the job (the idempotency key is the unit plus that
 * intent). A new intent is an intentional new attempt and does not replace the
 * unit or the earlier attempt.
 */
final readonly class StoryProductionUnitGenerationService implements StoryProductionUnitGenerationServiceInterface
{
    public const EVENT_REQUESTED = 'story.unit_generation.requested';

    public const EVENT_SUBMITTED = 'story.unit_generation.submitted';

    public const EVENT_COMPLETED = 'story.unit_generation.completed';

    public const EVENT_FAILED = 'story.unit_generation.failed';

    public const EVENT_RETRIED = 'story.unit_generation.retried';

    /**
     * Container timing may differ from the requested whole second by less than this.
     * A larger gap is rejected. The unit row is never rewritten to match the file.
     */
    public const DURATION_TOLERANCE_SECONDS = 0.5;

    /** @var list<string> */
    private const MODES = [
        StoryVideoCapability::TextToVideo->value,
        StoryVideoCapability::ImageToVideo->value,
        StoryVideoCapability::ReferenceToVideo->value,
    ];

    public function __construct(
        private StoryProductionPlanServiceInterface $plans,
        private StoryVideoEngineInterface $engine,
        private StoryVideoDispatchService $dispatch,
        private StoryVideoJobRunner $runner,
        private StoryVideoAssetResolver $assets,
        private StoryCapabilityRouterInterface $router,
        private StoryContinuityService $continuity,
        private StoryMediaToolkit $media,
        private ActivityLoggerInterface $activity,
    ) {}

    public function generate(Project $project, string $planUuid, string $unitUuid, User $actor, array $input): array
    {
        [$plan, $unit, $scene] = $this->ownedUnit($project, $planUuid, $unitUuid);
        $capability = $this->capability($input['capability'] ?? null);
        $intent = $this->intent($input['intent'] ?? null);
        $key = $this->idempotencyKey($unit, $intent);

        $existing = $this->findIntent($plan, $key);
        if ($existing instanceof StoryVideoGenerationJob) {
            $existing = $this->attachUnit($existing, $unit);

            return ['job' => $this->continueJob($existing, $unit), 'unit' => $unit, 'created' => false];
        }

        $this->assertReady($plan, $scene, $unit);
        $adapter = $this->connectedAdapter($project, $capability, $scene);
        $this->assertExactDuration($adapter, $capability, $unit);

        $aspect = $this->aspectRatio($input['aspect_ratio'] ?? null, $adapter, $capability);
        $request = $this->assets->resolve(
            $project,
            $this->ownedInputs($project, $this->requestFor($project, $plan, $unit, $scene, $capability, $adapter, $aspect, $key, $input)),
        );
        $decision = $this->engine->resolve($request);
        if (! $decision->matched || $decision->providerKey !== $adapter->key()) {
            throw StoryVideoEngineException::capabilityNotAvailable(
                'The connected video service cannot make this generation unit. Nothing was submitted.',
            );
        }

        try {
            $prepared = $this->engine->prepareJob($project, $request);
        } catch (UniqueConstraintViolationException) {
            $existing = $this->findIntent($plan, $key);
            if (! $existing instanceof StoryVideoGenerationJob) {
                throw StoryVideoEngineException::invalidInput('This generation is already being started. Check its status and try again.');
            }

            return ['job' => $this->continueJob($existing, $unit), 'unit' => $unit, 'created' => false];
        }

        $job = $this->attachUnit($prepared['job'], $unit);
        if ($prepared['created']) {
            $this->record($job, $actor, self::EVENT_REQUESTED, 'Generation requested', ['unit_sequence' => $unit->sequence]);
            Log::info('Unit generation requested.', ['job' => $job->uuid, 'unit' => $unit->uuid]);
        }

        return ['job' => $this->continueJob($job, $unit), 'unit' => $unit, 'created' => (bool) $prepared['created']];
    }

    public function refresh(Project $project, string $planUuid, string $unitUuid, string $jobUuid): StoryVideoGenerationJob
    {
        [, $unit] = $this->ownedUnit($project, $planUuid, $unitUuid);

        return $this->continueJob($this->jobFor($unit, $jobUuid), $unit);
    }

    public function latest(Project $project, string $planUuid, string $unitUuid): ?StoryVideoGenerationJob
    {
        [, $unit] = $this->ownedUnit($project, $planUuid, $unitUuid);

        return $this->attemptQuery($unit)->orderByDesc('id')->first();
    }

    public function attempts(Project $project, string $planUuid, string $unitUuid): Collection
    {
        [, $unit] = $this->ownedUnit($project, $planUuid, $unitUuid);

        return $this->attemptQuery($unit)->orderBy('id')->get();
    }

    public function cancel(Project $project, string $planUuid, string $unitUuid, string $jobUuid, User $actor): StoryVideoGenerationJob
    {
        [, $unit] = $this->ownedUnit($project, $planUuid, $unitUuid);
        $job = $this->jobFor($unit, $jobUuid);
        if (! $this->runner->inFlight($job)) {
            return $job;
        }

        $adapter = $this->adapterByKey((string) $job->provider_key);
        if ($adapter instanceof LiveStoryVideoProviderAdapterInterface) {
            $adapter->cancel($job);
        } else {
            $job->forceFill([
                'status' => StoryVideoJobStatus::Cancelled->value,
            ])->save();
        }
        $this->record($job->refresh(), $actor, self::EVENT_FAILED, 'Generation cancelled', ['status' => $job->status]);

        return $job->refresh();
    }

    /**
     * Submits a claimed job at most once, then accepts a finished file.
     * A repeat call polls; it does not submit again.
     */
    private function continueJob(StoryVideoGenerationJob $job, StoryProductionUnit $unit): StoryVideoGenerationJob
    {
        $retries = (int) $job->retry_count;
        $submittedNow = false;
        if ((string) $job->operation_id === '' && $job->started_at === null && $this->runner->inFlight($job)) {
            $submittedNow = true;
            if ($this->runner->queued()) {
                ProcessStoryVideoJob::dispatch($job->id);
            } else {
                try {
                    $job = $this->runner->submit($job);
                } catch (StoryVideoEngineException) {
                    $failed = $job->refresh();
                    $this->note($failed, $unit);
                    $code = StoryVideoErrorCode::tryFrom((string) $failed->error_code) ?? StoryVideoErrorCode::UpstreamError;
                    throw StoryVideoEngineException::provider($code, (string) ($failed->error_message ?: 'The video could not be submitted.'));
                }
            }
        } elseif ($this->runner->inFlight($job) && ! $submittedNow && (! $this->runner->queued() || ((string) $job->operation_id === '' && $job->started_at !== null))) {
            // Polling stays on the worker when the queue is on. A claimed submit with no
            // operation id is expired here too, so a status read cannot start a second one.
            $job = $this->runner->advance($job);
        }

        $job = $job->refresh();
        if ((int) $job->retry_count > $retries) {
            $this->record($job, null, self::EVENT_RETRIED, 'Generation retrying', ['retry_count' => (int) $job->retry_count]);
        }

        return $this->acceptOutput($job, $unit);
    }

    private function acceptOutput(StoryVideoGenerationJob $job, StoryProductionUnit $unit): StoryVideoGenerationJob
    {
        $this->note($job, $unit);
        if ($job->statusEnum() !== StoryVideoJobStatus::Completed) {
            return $job;
        }
        if (($job->provider_metadata['unit_output_checked'] ?? false) === true) {
            return $job;
        }

        $storage = $job->provider_metadata['storage'] ?? null;
        $disk = is_array($storage) ? (string) ($storage['disk'] ?? '') : '';
        $path = is_array($storage) ? (string) ($storage['path'] ?? '') : '';
        $mime = is_array($storage) ? (string) ($storage['mime'] ?? '') : '';
        if ($disk !== 'videos' || $path === '' || str_contains($path, '..') || ! str_starts_with($mime, 'video/') || ! Storage::disk('videos')->exists($path) || Storage::disk('videos')->size($path) < 1) {
            return $this->rejectOutput($job, 'The generated clip was missing or could not be read, so it was not accepted.');
        }

        $reported = is_array($storage) && is_numeric($storage['duration_seconds'] ?? null) ? (float) $storage['duration_seconds'] : null;
        if ($reported === null && $this->media->available()) {
            try {
                $reported = (float) $this->media->probe(Storage::disk('videos')->path($path))['duration'];
            } catch (StoryMediaException) {
                $reported = null;
            }
        }
        if ($reported === null) {
            return $this->rejectOutput($job, 'The generated clip\'s length could not be checked, so it was not accepted.');
        }
        if (abs($reported - $unit->duration_seconds) > self::DURATION_TOLERANCE_SECONDS) {
            return $this->rejectOutput($job, 'The generated clip is not the length of this generation unit, so it was not accepted.');
        }

        $metadata = (array) $job->provider_metadata;
        $metadata['unit_output_checked'] = true;
        $metadata['storage']['duration_seconds'] = round($reported, 2);
        $job->forceFill(['provider_metadata' => $metadata])->save();
        $this->record($job, null, self::EVENT_COMPLETED, 'Generation completed', [
            'unit_sequence' => $unit->sequence,
            'duration_seconds' => $unit->duration_seconds,
        ]);
        Log::info('Unit generation stored.', ['job' => $job->uuid, 'unit' => $unit->uuid]);

        return $job->refresh();
    }

    private function rejectOutput(StoryVideoGenerationJob $job, string $message): StoryVideoGenerationJob
    {
        $job->forceFill([
            'status' => StoryVideoJobStatus::Failed->value,
            'error_code' => StoryVideoErrorCode::InvalidProviderResponse->value,
            'error_message' => $message,
            'failed_at' => now(),
        ])->save();
        $this->record($job, null, self::EVENT_FAILED, 'Generation failed', ['error_code' => $job->error_code]);
        Log::warning('Unit generation output rejected.', ['job' => $job->uuid, 'error_code' => $job->error_code]);

        return $job->refresh();
    }

    private function note(StoryVideoGenerationJob $job, StoryProductionUnit $unit): void
    {
        if ((string) $job->operation_id !== '') {
            $this->record($job, null, self::EVENT_SUBMITTED, 'Generation submitted', ['unit_sequence' => $unit->sequence]);
        }
        if (in_array($job->statusEnum(), [StoryVideoJobStatus::Failed, StoryVideoJobStatus::Cancelled], true)) {
            $this->record($job, null, self::EVENT_FAILED, 'Generation failed', [
                'error_code' => $job->error_code,
                'status' => $job->status,
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $properties
     */
    private function record(StoryVideoGenerationJob $job, ?User $actor, string $action, string $description, array $properties): void
    {
        $exists = DB::table('activity_logs')
            ->where('action', $action)
            ->where('subject_type', $job->getMorphClass())
            ->where('subject_id', $job->id)
            ->exists();
        if ($exists) {
            return;
        }

        try {
            $this->activity->log($action, $job, $actor, $description, $properties);
        } catch (Throwable $exception) {
            Log::warning('Unit generation history could not be recorded.', [
                'job' => $job->uuid,
                'exception' => $exception::class,
            ]);
        }
    }

    /**
     * @return array{0: StoryProductionPlan, 1: StoryProductionUnit, 2: StoryScene}
     */
    private function ownedUnit(Project $project, string $planUuid, string $unitUuid): array
    {
        $plan = $this->plans->getForProject($project, $planUuid)->loadMissing('sourceVersion', 'workspace');
        $unit = StoryProductionUnit::query()
            ->where('uuid', $unitUuid)
            ->whereHas('planScene', static function ($query) use ($plan): void {
                $query->where('story_production_plan_id', $plan->id);
            })
            ->first();
        if (! $unit instanceof StoryProductionUnit) {
            throw StoryException::notFound('Generation unit');
        }

        $unit->loadMissing('planScene.scene.reel', 'planScene.plan.sourceVersion');
        $scene = $unit->planScene?->scene;
        if (! $scene instanceof StoryScene || (int) $scene->story_reel_id !== (int) $plan->story_reel_id) {
            throw StoryException::notFound('Generation unit');
        }

        return [$plan, $unit, $scene];
    }

    private function assertReady(StoryProductionPlan $plan, StoryScene $scene, StoryProductionUnit $unit): void
    {
        $version = $plan->sourceVersion;
        if ($version === null || ! $version->isApproved()) {
            throw StoryVideoEngineException::invalidInput('Approve this story version before generating it.');
        }
        if ($scene->status === StorySceneStatus::Archived->value) {
            throw StoryVideoEngineException::invalidInput('This scene is archived, so its generation units cannot be made.');
        }
        if ($unit->duration_seconds < 1 || $unit->duration_seconds > StoryGenerationUnitCalculator::UNIT_SECONDS) {
            throw StoryVideoEngineException::invalidInput('This generation unit has a length the production plan does not allow.');
        }
        if (trim((string) $scene->visual_prompt) === '' && trim((string) $scene->story) === '') {
            throw StoryVideoEngineException::invalidInput('This scene has no picture direction yet, so the unit cannot be generated.');
        }
    }

    private function connectedAdapter(Project $project, StoryVideoCapability $capability, StoryScene $scene): LiveStoryVideoProviderAdapterInterface
    {
        $scene->loadMissing('reel');
        $this->dispatch->assertLive($project, $capability, $scene->reel?->uuid, $scene->uuid);
        $adapter = $this->dispatch->liveAdapterFor($capability);
        if (! $adapter instanceof LiveStoryVideoProviderAdapterInterface) {
            throw StoryVideoEngineException::generationNotEnabled();
        }

        return $adapter;
    }

    private function assertExactDuration(
        LiveStoryVideoProviderAdapterInterface $adapter,
        StoryVideoCapability $capability,
        StoryProductionUnit $unit,
    ): void {
        $seconds = $unit->duration_seconds;
        $supported = $adapter->supportedDurations($capability);
        $fits = $supported !== []
            ? in_array($seconds, $supported, true)
            : ($adapter->minDurationSeconds($capability) === null || $seconds >= $adapter->minDurationSeconds($capability))
                && ($adapter->maxDurationSeconds($capability) === null || $seconds <= $adapter->maxDurationSeconds($capability));
        if ($fits) {
            return;
        }

        throw StoryVideoEngineException::capabilityNotAvailable(
            'This generation unit is '.$seconds.' seconds, and the connected video service cannot make a clip of that exact length. Nothing was submitted.',
        );
    }

    private function aspectRatio(mixed $requested, LiveStoryVideoProviderAdapterInterface $adapter, StoryVideoCapability $capability): ?string
    {
        if ($requested === null || $requested === '') {
            return null;
        }
        if (! is_string($requested)) {
            throw StoryVideoEngineException::invalidInput('The picture shape is not supported.');
        }
        $ratios = $adapter->supportedAspectRatios($capability);
        if ($ratios !== [] && ! in_array($requested, $ratios, true)) {
            throw StoryVideoEngineException::invalidInput('The picture shape is not supported.');
        }

        return $requested;
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function requestFor(
        Project $project,
        StoryProductionPlan $plan,
        StoryProductionUnit $unit,
        StoryScene $scene,
        StoryVideoCapability $capability,
        LiveStoryVideoProviderAdapterInterface $adapter,
        ?string $aspect,
        string $key,
        array $input,
    ): StoryVideoGenerationRequest {
        $scene->loadMissing('reel');
        $instruction = is_string($input['instruction'] ?? null) ? trim($input['instruction']) : '';
        $prompt = trim(implode("\n\n", array_filter([
            $scene->visual_prompt,
            $scene->motion_prompt,
            $scene->story,
            $instruction !== '' ? 'Instruction: '.$instruction : null,
        ])));

        return new StoryVideoGenerationRequest(
            capability: $capability,
            workspaceUuid: $plan->workspace?->uuid,
            reelUuid: $scene->reel?->uuid,
            sceneUuid: $scene->uuid,
            prompt: $prompt,
            durationSeconds: $unit->duration_seconds,
            aspectRatio: $aspect,
            inputs: $this->inputs($input['inputs'] ?? []),
            preferredProvider: $adapter->key(),
            idempotencyKey: $key,
            metadata: [
                'production_unit_id' => $unit->uuid,
                'context' => $this->snapshot($project, $plan, $unit, $scene, $capability, $aspect, $instruction),
            ],
        );
    }

    /**
     * Every supplied file is checked against this project before the mode's own check.
     * A text request cannot carry an image from another project.
     */
    private function ownedInputs(Project $project, StoryVideoGenerationRequest $request): StoryVideoGenerationRequest
    {
        if ($request->inputs === []) {
            return $request;
        }

        $inputs = [];
        foreach ($request->inputs as $input) {
            $capability = match ($input->type) {
                StoryVideoInputType::Text => null,
                StoryVideoInputType::Image => StoryVideoCapability::ImageToVideo,
                StoryVideoInputType::ReferenceImage => StoryVideoCapability::ReferenceToVideo,
                StoryVideoInputType::Video => StoryVideoCapability::VideoEdit,
                default => throw StoryVideoEngineException::invalidInput('The reference is not valid.'),
            };
            if ($capability === null) {
                $inputs[] = $input;

                continue;
            }

            $checked = $this->assets->resolve($project, new StoryVideoGenerationRequest(
                capability: $capability,
                inputs: [$input],
            ));
            $inputs[] = $checked->inputs[0] ?? $input;
        }

        return new StoryVideoGenerationRequest(
            capability: $request->capability,
            workspaceUuid: $request->workspaceUuid,
            reelUuid: $request->reelUuid,
            sceneUuid: $request->sceneUuid,
            prompt: $request->prompt,
            negativePrompt: $request->negativePrompt,
            durationSeconds: $request->durationSeconds,
            aspectRatio: $request->aspectRatio,
            resolution: $request->resolution,
            audioRequested: $request->audioRequested,
            inputs: $inputs,
            preferredProvider: $request->preferredProvider,
            preferredModel: $request->preferredModel,
            idempotencyKey: $request->idempotencyKey,
            metadata: $request->metadata,
            extensions: $request->extensions,
        );
    }

    /**
     * @return list<StoryVideoInput>
     */
    private function inputs(mixed $rows): array
    {
        if ($rows === null || $rows === []) {
            return [];
        }
        if (! is_array($rows)) {
            throw StoryVideoEngineException::invalidInput('The reference is not valid.');
        }

        $inputs = [];
        foreach (array_values($rows) as $index => $row) {
            if (! is_array($row)) {
                throw StoryVideoEngineException::invalidInput('The reference is not valid.');
            }
            $type = StoryVideoInputType::tryFrom((string) ($row['type'] ?? ''));
            $assetId = isset($row['asset_id']) ? (string) $row['asset_id'] : '';
            if ($type === null || $assetId === '') {
                throw StoryVideoEngineException::invalidInput('The reference is not valid.');
            }
            $inputs[] = new StoryVideoInput(type: $type, assetId: $assetId, order: $index);
        }

        return $inputs;
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(
        Project $project,
        StoryProductionPlan $plan,
        StoryProductionUnit $unit,
        StoryScene $scene,
        StoryVideoCapability $capability,
        ?string $aspect,
        string $instruction,
    ): array {
        $scene->loadMissing('reel');
        $package = $scene->reel === null ? [] : $this->continuity->packageForScene($project, $scene->reel->uuid, $scene->uuid);
        $previous = $this->previousUnit($unit);

        return [
            'unit' => [
                'id' => $unit->uuid,
                'sequence' => $unit->sequence,
                'start_second' => $unit->start_second,
                'duration_seconds' => $unit->duration_seconds,
            ],
            'scene' => [
                'id' => $scene->uuid,
                'sequence' => $scene->sequence,
                'title' => $scene->title,
                'story' => $scene->story,
                'visual_prompt' => $scene->visual_prompt,
                'motion_prompt' => $scene->motion_prompt,
                'location' => $scene->location,
            ],
            'plan' => ['id' => $plan->uuid, 'revision' => $plan->revision],
            'source_version' => [
                'id' => $plan->sourceVersion?->uuid,
                'version' => $plan->sourceVersion?->version,
            ],
            'story' => $package['story_bible'] ?? null,
            'style' => $package['style_bible'] ?? null,
            'style_reference_ids' => $package['style_reference_ids'] ?? [],
            'characters' => $package['characters'] ?? [],
            'mode' => $capability->value,
            'aspect_ratio' => $aspect,
            'instruction' => $instruction !== '' ? $instruction : null,
            'requested_duration_seconds' => $unit->duration_seconds,
            'continuity' => [
                'previous_scene' => $package['previous_scene'] ?? null,
                'previous_unit' => $previous,
            ],
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function previousUnit(StoryProductionUnit $unit): ?array
    {
        if ($unit->sequence < 2) {
            return null;
        }

        $previous = StoryProductionUnit::query()
            ->where('story_production_plan_scene_id', $unit->story_production_plan_scene_id)
            ->where('sequence', $unit->sequence - 1)
            ->first();
        if (! $previous instanceof StoryProductionUnit) {
            return ['available' => false];
        }

        $job = StoryVideoGenerationJob::query()
            ->where('story_production_unit_id', $previous->id)
            ->where('status', StoryVideoJobStatus::Completed->value)
            ->orderByDesc('id')
            ->first();
        $storage = $job?->provider_metadata['storage'] ?? null;
        $output = is_array($storage) && ($storage['disk'] ?? null) === 'videos' && is_string($storage['path'] ?? null)
            ? ['disk' => 'videos', 'path' => $storage['path']]
            : null;

        return [
            'available' => $output !== null,
            'id' => $previous->uuid,
            'sequence' => $previous->sequence,
            'output' => $output,
        ];
    }

    private function capability(mixed $value): StoryVideoCapability
    {
        $capability = is_string($value) ? StoryVideoCapability::tryFrom($value) : null;
        if ($capability === null || ! in_array($capability->value, self::MODES, true)) {
            throw StoryVideoEngineException::invalidInput('This generation mode is not supported for a generation unit.');
        }

        return $capability;
    }

    private function intent(mixed $value): string
    {
        if ($value === null || $value === '') {
            return 'initial';
        }
        if (! is_string($value) || preg_match('/^[A-Za-z0-9_-]{1,64}$/', $value) !== 1) {
            throw StoryVideoEngineException::invalidInput('The generation intent is not valid.');
        }

        return $value;
    }

    private function idempotencyKey(StoryProductionUnit $unit, string $intent): string
    {
        return 'production-unit:'.$unit->uuid.':'.$intent;
    }

    private function findIntent(StoryProductionPlan $plan, string $key): ?StoryVideoGenerationJob
    {
        return StoryVideoGenerationJob::query()
            ->where('story_workspace_id', $plan->story_workspace_id)
            ->where('idempotency_key', $key)
            ->first();
    }

    private function jobFor(StoryProductionUnit $unit, string $jobUuid): StoryVideoGenerationJob
    {
        $job = $this->attemptQuery($unit)->where('uuid', $jobUuid)->first();
        if (! $job instanceof StoryVideoGenerationJob) {
            throw StoryException::notFound('Generation job');
        }

        return $job;
    }

    private function attachUnit(StoryVideoGenerationJob $job, StoryProductionUnit $unit): StoryVideoGenerationJob
    {
        if ($job->story_production_unit_id === null) {
            $job->forceFill(['story_production_unit_id' => $unit->id])->save();
        } elseif ((int) $job->story_production_unit_id !== (int) $unit->id) {
            throw StoryVideoEngineException::invalidInput('This generation intent is already in use.');
        }

        return $job;
    }

    /**
     * @return Builder<StoryVideoGenerationJob>
     */
    private function attemptQuery(StoryProductionUnit $unit): Builder
    {
        return StoryVideoGenerationJob::query()->where('story_production_unit_id', $unit->id);
    }

    private function adapterByKey(string $key): ?LiveStoryVideoProviderAdapterInterface
    {
        foreach ($this->router->adapters() as $adapter) {
            if ($adapter instanceof LiveStoryVideoProviderAdapterInterface && $adapter->key() === $key) {
                return $adapter;
            }
        }

        return null;
    }
}
