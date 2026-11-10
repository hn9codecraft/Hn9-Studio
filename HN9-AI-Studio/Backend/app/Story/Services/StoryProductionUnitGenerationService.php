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
use App\Story\Contracts\StoryProductionUnitVersionServiceInterface;
use App\Story\Contracts\StoryVideoEngineInterface;
use App\Story\Enums\StorySceneStatus;
use App\Story\Enums\StoryVideoCapability;
use App\Story\Enums\StoryVideoErrorCode;
use App\Story\Enums\StoryVideoInputType;
use App\Story\Enums\StoryVideoJobStatus;
use App\Story\Exceptions\StoryException;
use App\Story\Exceptions\StoryPromptPreviewException;
use App\Story\Exceptions\StoryUnitRetryException;
use App\Story\Exceptions\StoryVideoEngineException;
use App\Story\Media\StoryMediaException;
use App\Story\Media\StoryMediaToolkit;
use App\Story\Models\StoryProductionPlan;
use App\Story\Models\StoryProductionUnit;
use App\Story\Models\StoryScene;
use App\Story\Models\StoryUnitPromptEdit;
use App\Story\Models\StoryVideoGenerationJob;
use App\Story\Support\StoryComposedPrompt;
use App\Story\Support\StoryFailureReason;
use App\Story\Support\StoryGenerationUnitCalculator;
use App\Story\Support\StoryLockStatus;
use App\Story\Support\StoryPromptHash;
use App\Story\Support\StoryVideoPromptComposer;
use App\Story\Video\StoryVideoAssetResolver;
use App\Story\Video\StoryVideoGenerationRequest;
use App\Story\Video\StoryVideoInput;
use App\Story\Video\StoryVideoProviderOrchestrator;
use App\Story\Video\StoryVideoRoutingDecision;
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
 * a scene or round a remainder up. Which provider runs the attempt is decided
 * once, before submission, by StoryVideoProviderOrchestrator.
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

    public const EVENT_RETRY_REQUESTED = 'story.unit_generation.retry_requested';

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
        private StoryVideoProviderOrchestrator $orchestrator,
        private StoryVideoJobRunner $runner,
        private StoryVideoAssetResolver $assets,
        private StoryCapabilityRouterInterface $router,
        private StoryContinuityService $continuity,
        private StoryMediaToolkit $media,
        private ActivityLoggerInterface $activity,
        private StoryProductionUnitVersionServiceInterface $versions,
        private StoryVideoPromptComposer $composer,
    ) {}

    /**
     * Controller pre-check. An idempotent repeat is not priced again.
     *
     * @param  array<string, mixed>  $input
     */
    public function precheckBudget(Project $project, string $planUuid, string $unitUuid, array $input): void
    {
        [$plan, $unit] = $this->ownedUnit($project, $planUuid, $unitUuid);
        if ($this->findIntent($plan, $this->idempotencyKey($unit, $this->intent($input['intent'] ?? null))) instanceof StoryVideoGenerationJob) {
            return;
        }

        app(StoryBudgetGuard::class)->assertWithinBudget(
            $project,
            app(StoryCostEstimator::class)->estimate($project, 'unit_video', [
                'plan_uuid' => $planUuid,
                'unit_uuid' => $unitUuid,
            ]),
        );
    }

    public function generate(Project $project, string $planUuid, string $unitUuid, User $actor, array $input): array
    {
        [$plan, $unit, $scene] = $this->ownedUnit($project, $planUuid, $unitUuid);
        $capability = $this->capability($input['capability'] ?? null);
        $intent = $this->intent($input['intent'] ?? null);
        $key = $this->idempotencyKey($unit, $intent);

        $existing = $this->findIntent($plan, $key);
        if ($existing instanceof StoryVideoGenerationJob) {
            return $this->reuseIntent($existing, $unit, $intent);
        }

        $this->assertPreviewCurrent($project, $planUuid, $unitUuid, $input);
        $this->precheckBudget($project, $planUuid, $unitUuid, $input);
        $this->assertReady($plan, $scene, $unit);
        $draft = $this->draft($project, $planUuid, $unitUuid, $input);
        $decision = $draft['decision'];
        if (! $decision->matched || $decision->providerKey === null) {
            $this->throwRouting($decision);
        }
        $request = $draft['request'];
        $resolved = $this->engine->resolve($request);
        if (! $resolved->matched || $resolved->providerKey !== $decision->providerKey) {
            throw StoryVideoEngineException::capabilityNotAvailable(
                'The selected video service cannot make this generation unit. Nothing was submitted.',
            );
        }

        try {
            $prepared = $this->engine->prepareJob($project, $request);
        } catch (UniqueConstraintViolationException) {
            $existing = $this->findIntent($plan, $key);
            if (! $existing instanceof StoryVideoGenerationJob) {
                throw StoryVideoEngineException::invalidInput('This generation is already being started. Check its status and try again.');
            }

            return $this->reuseIntent($existing, $unit, $intent);
        }

        $job = $this->attachUnit($prepared['job'], $unit);
        if ($prepared['created']) {
            $metadata = (array) $job->provider_metadata;
            $metadata['routing_decision'] = $this->orchestrator->snapshot($request, $decision);
            $metadata['lock_provenance'] = $draft['provenance'];
            $job->forceFill([
                'provider_metadata' => $metadata,
                'routing' => $metadata['routing_decision'],
            ])->save();
            $this->record($job, $actor, self::EVENT_REQUESTED, 'Generation requested', ['unit_sequence' => $unit->sequence]);
            Log::info('Unit generation requested.', [
                'job' => $job->uuid,
                'unit' => $unit->uuid,
                'provider' => $decision->providerKey,
                'routing_reason' => $metadata['routing_decision']['reason'] ?? null,
            ]);
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

    /**
     * A new attempt for a failed or cancelled unit. The intent and idempotency key are new.
     * The request itself is the current generate() path, including the budget guard and the prompt preview hash.
     *
     * @param  array<string, mixed>  $input
     * @return array{job: StoryVideoGenerationJob, unit: StoryProductionUnit, created: bool, attempt_number: int, retries_used: int, max_retries: int}
     */
    public function retry(Project $project, string $planUuid, string $unitUuid, User $actor, array $input): array
    {
        [, $unit] = $this->ownedUnit($project, $planUuid, $unitUuid);
        $latest = $this->latest($project, $planUuid, $unitUuid);
        if (! $latest instanceof StoryVideoGenerationJob || ! $this->terminal($latest)) {
            throw StoryUnitRetryException::notAllowed($latest instanceof StoryVideoGenerationJob ? (string) $latest->status : 'none');
        }
        $failure = StoryFailureReason::forJob($latest);
        if (! $failure['retryable']) {
            throw StoryUnitRetryException::notRetryable($failure['reason']);
        }
        $max = $this->maxRetries();
        $used = $this->retriesUsed($unit);
        if ($max < 1 || $used >= $max) {
            throw StoryUnitRetryException::limit($max);
        }

        $input['intent'] = self::retryIntent($latest);
        if (! is_string($input['capability'] ?? null) || $input['capability'] === '') {
            $input['capability'] = $latest->capability;
        }

        $result = $this->generate($project, $planUuid, $unitUuid, $actor, $input);
        $job = $result['job'];
        if ($result['created']) {
            $metadata = (array) $job->provider_metadata;
            $metadata['retry_of'] = [
                'job_uuid' => $latest->uuid,
                'attempt_number' => $this->attemptNumber($unit, $latest),
            ];
            $job->forceFill(['provider_metadata' => $metadata])->save();
            $this->activity->log(self::EVENT_RETRY_REQUESTED, $job, $actor, 'Retry requested', [
                'unit_uuid' => $unit->uuid,
                'from_job_uuid' => $latest->uuid,
                'attempt_number' => $this->attemptNumber($unit, $job->fresh() ?? $job),
                'reason_code' => (string) $latest->error_code,
            ]);
            $job = $job->fresh() ?? $job;
        }

        return [
            'job' => $job,
            'unit' => $unit,
            'created' => (bool) $result['created'],
            'attempt_number' => $this->attemptNumber($unit, $job),
            'retries_used' => $this->retriesUsed($unit),
            'max_retries' => $max,
        ];
    }

    /**
     * Read-only retry state for the prompt preview. It does not spend or create a job.
     *
     * @return array{allowed: bool, reason: string|null, intent: string, capability: string|null, attempt_number_next: int, retries_left: int, retries_used: int, max_retries: int}
     */
    public function retryContext(Project $project, string $planUuid, string $unitUuid): array
    {
        [, $unit] = $this->ownedUnit($project, $planUuid, $unitUuid);
        $latest = $this->latest($project, $planUuid, $unitUuid);
        $max = $this->maxRetries();
        $used = $this->retriesUsed($unit);
        $total = $this->attemptQuery($unit)->count();
        $failure = $latest instanceof StoryVideoGenerationJob && $this->terminal($latest)
            ? StoryFailureReason::forJob($latest)
            : null;
        $allowed = $failure !== null && $failure['retryable'] && $max >= 1 && $used < $max;
        $reason = null;
        if (! $latest instanceof StoryVideoGenerationJob) {
            $reason = 'There is no failed attempt to retry.';
        } elseif (! $this->terminal($latest)) {
            $reason = 'This unit cannot be retried while its latest attempt is '.$latest->status.'.';
        } elseif ($failure !== null && ! $failure['retryable']) {
            $reason = $failure['reason'];
        } elseif ($max < 1 || $used >= $max) {
            $reason = 'This unit has used all of its retries.';
        }

        return [
            'allowed' => $allowed,
            'reason' => $allowed ? null : $reason,
            'intent' => $latest instanceof StoryVideoGenerationJob ? self::retryIntent($latest) : 'retry-none',
            'capability' => $latest instanceof StoryVideoGenerationJob ? (string) $latest->capability : null,
            'attempt_number_next' => $total + 1,
            'retries_left' => max(0, $max - $used),
            'retries_used' => $used,
            'max_retries' => $max,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function attemptHistory(Project $project, string $planUuid, string $unitUuid): array
    {
        [, $unit] = $this->ownedUnit($project, $planUuid, $unitUuid);
        $ordered = $this->attemptQuery($unit)->orderBy('id')->pluck('id');
        $numbers = [];
        foreach ($ordered as $index => $id) {
            $numbers[(int) $id] = $index + 1;
        }
        $jobs = $this->attemptQuery($unit)->orderByDesc('id')->limit(20)->get();
        $rows = [];
        foreach ($jobs as $job) {
            $terminal = $this->terminal($job);
            $finished = $job->completed_at ?? $job->failed_at;
            $retryOf = is_array($job->provider_metadata['retry_of'] ?? null) ? $job->provider_metadata['retry_of'] : null;
            $rows[] = [
                'job_uuid' => $job->uuid,
                'attempt_number' => $numbers[(int) $job->id] ?? 1,
                'intent_kind' => $this->intentKind($job->idempotency_key),
                'status' => $job->status,
                'failure' => $terminal ? StoryFailureReason::forJob($job) : null,
                'provider_retries' => (int) $job->retry_count,
                'retry_of' => $retryOf,
                'created_at' => $job->created_at?->toIso8601String(),
                'finished_at' => $finished?->toIso8601String(),
                'lock_status' => StoryLockStatus::forProvenance(
                    is_array($job->provider_metadata['lock_provenance'] ?? null) ? $job->provider_metadata['lock_provenance'] : null,
                ),
            ];
        }

        return $rows;
    }

    public static function retryIntent(StoryVideoGenerationJob $failedJob): string
    {
        return 'retry-'.substr(str_replace('-', '', $failedJob->uuid), 0, 24);
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

        DB::transaction(function () use ($job, $unit, $reported, $disk, $path, $mime): void {
            $locked = StoryVideoGenerationJob::query()->whereKey($job->id)->lockForUpdate()->first();
            if (! $locked instanceof StoryVideoGenerationJob || ($locked->provider_metadata['unit_output_checked'] ?? false) === true) {
                return;
            }
            $this->versions->recordAccepted($locked, $unit, $reported, $disk, $path, $mime);
            $metadata = (array) $locked->provider_metadata;
            $metadata['unit_output_checked'] = true;
            $metadata['storage']['duration_seconds'] = round($reported, 2);
            $locked->forceFill(['provider_metadata' => $metadata])->save();
        });
        $job = $job->refresh();
        $this->record($job, null, self::EVENT_COMPLETED, 'Generation completed', [
            'unit_sequence' => $unit->sequence,
            'duration_seconds' => $unit->duration_seconds,
        ]);
        Log::info('Unit generation stored.', ['job' => $job->uuid, 'unit' => $unit->uuid]);

        return $job;
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

    private function requestedAspect(mixed $requested): ?string
    {
        if ($requested === null || $requested === '') {
            return null;
        }
        if (! is_string($requested)) {
            throw StoryVideoEngineException::invalidInput('The picture shape is not supported.');
        }

        return $requested;
    }

    private function throwRouting(StoryVideoRoutingDecision $decision): never
    {
        $message = (string) ($decision->errorMessage ?: 'Video generation is not available for this scene right now.');
        $code = StoryVideoErrorCode::tryFrom((string) $decision->errorCode);
        if ($code === StoryVideoErrorCode::GenerationNotEnabled) {
            throw StoryVideoEngineException::generationNotEnabled($message);
        }
        if ($code === StoryVideoErrorCode::InvalidInput) {
            throw StoryVideoEngineException::invalidInput($message);
        }

        throw StoryVideoEngineException::capabilityNotAvailable($message);
    }

    private function withProvider(StoryVideoGenerationRequest $request, string $provider, ?string $model): StoryVideoGenerationRequest
    {
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
            inputs: $request->inputs,
            preferredProvider: $provider,
            preferredModel: $model,
            idempotencyKey: $request->idempotencyKey,
            metadata: $request->metadata,
            extensions: $request->extensions,
        );
    }

    /**
     * Rejects a paid generate when the preview the user confirmed is no longer the request
     * the server would send. An idempotent repeat is left to return the existing job.
     *
     * @param  array<string, mixed>  $input
     */
    public function assertPreviewCurrent(Project $project, string $planUuid, string $unitUuid, array $input): void
    {
        $hash = $input['preview_hash'] ?? null;
        if (! is_string($hash) || $hash === '') {
            return;
        }
        [$plan, $unit] = $this->ownedUnit($project, $planUuid, $unitUuid);
        $key = $this->idempotencyKey($unit, $this->intent($input['intent'] ?? null));
        if ($this->findIntent($plan, $key) instanceof StoryVideoGenerationJob) {
            return;
        }

        $draft = $this->draft($project, $planUuid, $unitUuid, $input);
        if (strlen($hash) !== 64 || ! hash_equals($draft['preview_hash'], strtolower($hash))) {
            throw StoryPromptPreviewException::stale();
        }
    }

    /**
     * Read-only composition of the request a generate would send. No job row and no budget guard.
     *
     * @param  array<string, mixed>  $input
     * @return array{request: StoryVideoGenerationRequest, decision: StoryVideoRoutingDecision, composed: StoryComposedPrompt, preview_hash: string, provenance: array<string, mixed>, matched: bool, idempotent: bool}
     */
    public function draft(Project $project, string $planUuid, string $unitUuid, array $input): array
    {
        [$plan, $unit, $scene] = $this->ownedUnit($project, $planUuid, $unitUuid);
        $capability = $this->capability($input['capability'] ?? null);
        $aspect = $this->requestedAspect($input['aspect_ratio'] ?? null);
        $key = $this->idempotencyKey($unit, $this->intent($input['intent'] ?? null));
        $idempotent = $this->findIntent($plan, $key) instanceof StoryVideoGenerationJob;
        $instruction = is_string($input['instruction'] ?? null) ? trim($input['instruction']) : '';
        $built = $this->ownedInputs($project, $this->requestFor($project, $plan, $unit, $scene, $capability, $aspect, $key, $input));
        $request = $this->hasRequiredPicture($built) ? $this->assets->resolve($project, $built) : $built;
        $decision = $this->orchestrator->route($request);
        $provider = $decision->matched ? $decision->providerKey : null;
        $model = $decision->matched ? $decision->modelKey : null;
        if (is_string($provider) && $provider !== '') {
            $request = $this->withProvider($request, $provider, $model);
        }
        $composed = $this->composer->composeUnit(
            $scene,
            $unit,
            $capability,
            $provider,
            $request->inputs,
            $instruction,
            $project->uuid,
        );
        if ($composed->final !== (string) $request->prompt || ! $this->sameInputs($request->inputs, $composed->inputs)) {
            $request = $this->assets->resolve(
                $project,
                $this->ownedInputs($project, $this->withPrompt($request, $composed->final, $composed->inputs)),
            );
        }
        $estimate = app(StoryCostEstimator::class)->estimate($project, 'unit_video', [
            'plan_uuid' => $planUuid,
            'unit_uuid' => $unitUuid,
        ]);
        $ids = array_map(static fn (StoryVideoInput $row): string => (string) $row->assetId, $request->inputs);
        $previewHash = StoryPromptHash::make(
            $provider,
            $model,
            $capability->value,
            (int) $unit->duration_seconds,
            $aspect,
            $composed->final,
            $ids,
            $estimate,
        );

        return [
            'request' => $request,
            'decision' => $decision,
            'composed' => $composed,
            'preview_hash' => $previewHash,
            'provenance' => [
                'locks' => $composed->provenanceLocks,
                'prompt_hash' => $previewHash,
                'edited' => $composed->edited,
                'references_sent' => $composed->referencesSent,
            ],
            'matched' => $decision->matched && $decision->providerKey !== null,
            'idempotent' => $idempotent,
        ];
    }

    /**
     * @param  list<StoryVideoInput>  $left
     * @param  list<StoryVideoInput>  $right
     */
    private function sameInputs(array $left, array $right): bool
    {
        if (count($left) !== count($right)) {
            return false;
        }
        foreach ($left as $index => $input) {
            $other = $right[$index];
            if ($input->type !== $other->type || $input->assetId !== $other->assetId) {
                return false;
            }
        }

        return true;
    }

    private function hasRequiredPicture(StoryVideoGenerationRequest $request): bool
    {
        if (! in_array($request->capability, [StoryVideoCapability::ImageToVideo, StoryVideoCapability::ReferenceToVideo], true)) {
            return true;
        }
        $needed = $request->capability === StoryVideoCapability::ImageToVideo
            ? StoryVideoInputType::Image
            : StoryVideoInputType::ReferenceImage;
        foreach ($request->inputs as $input) {
            if ($input->type === $needed) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<StoryVideoInput>  $inputs
     */
    private function withPrompt(StoryVideoGenerationRequest $request, string $prompt, array $inputs): StoryVideoGenerationRequest
    {
        return new StoryVideoGenerationRequest(
            capability: $request->capability,
            workspaceUuid: $request->workspaceUuid,
            reelUuid: $request->reelUuid,
            sceneUuid: $request->sceneUuid,
            prompt: $prompt,
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
     * @param  array<string, mixed>  $input
     */
    private function requestFor(
        Project $project,
        StoryProductionPlan $plan,
        StoryProductionUnit $unit,
        StoryScene $scene,
        StoryVideoCapability $capability,
        ?string $aspect,
        string $key,
        array $input,
    ): StoryVideoGenerationRequest {
        $scene->loadMissing('reel');
        $instruction = is_string($input['instruction'] ?? null) ? trim($input['instruction']) : '';
        $unit->loadMissing('promptEdit');
        $prompt = $unit->promptEdit instanceof StoryUnitPromptEdit
            ? (string) $unit->promptEdit->prompt
            : $this->composer->automaticBase($scene, $instruction);

        return new StoryVideoGenerationRequest(
            capability: $capability,
            workspaceUuid: $plan->workspace?->uuid,
            reelUuid: $scene->reel?->uuid,
            sceneUuid: $scene->uuid,
            prompt: $prompt,
            durationSeconds: $unit->duration_seconds,
            aspectRatio: $aspect,
            inputs: $this->inputs($input['inputs'] ?? []),
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

        return $this->versions->continuityOutput($previous);
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

    /**
     * @return array{job: StoryVideoGenerationJob, unit: StoryProductionUnit, created: bool}
     */
    private function reuseIntent(StoryVideoGenerationJob $existing, StoryProductionUnit $unit, string $intent): array
    {
        $existing = $this->attachUnit($existing, $unit);
        if ($this->terminal($existing) && ! str_starts_with($intent, 'retry-')) {
            $failure = StoryFailureReason::forJob($existing);
            $used = $this->retriesUsed($unit);
            $max = $this->maxRetries();

            throw StoryUnitRetryException::failed(
                $existing,
                $failure,
                $failure['retryable'] && $max >= 1 && $used < $max,
            );
        }

        return ['job' => $this->continueJob($existing, $unit), 'unit' => $unit, 'created' => false];
    }

    private function terminal(StoryVideoGenerationJob $job): bool
    {
        return in_array($job->statusEnum(), [StoryVideoJobStatus::Failed, StoryVideoJobStatus::Cancelled], true);
    }

    private function maxRetries(): int
    {
        return max(0, (int) config('story_retry.max_user_retries_per_unit', 5));
    }

    private function retriesUsed(StoryProductionUnit $unit): int
    {
        return $this->attemptQuery($unit)->get()->filter(function (StoryVideoGenerationJob $job): bool {
            return is_array($job->provider_metadata['retry_of'] ?? null);
        })->count();
    }

    private function attemptNumber(StoryProductionUnit $unit, StoryVideoGenerationJob $job): int
    {
        $ids = $this->attemptQuery($unit)->orderBy('id')->pluck('id');
        $index = $ids->search($job->id);

        return $index === false ? 1 : ((int) $index) + 1;
    }

    private function intentKind(?string $key): string
    {
        $intent = '';
        if (is_string($key) && preg_match('/:([^:]+)$/', $key, $match) === 1) {
            $intent = $match[1];
        }
        if (str_starts_with($intent, 'retry-')) {
            return 'retry';
        }
        if (str_starts_with($intent, 'clip-')) {
            return 'clip';
        }

        return 'initial';
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
