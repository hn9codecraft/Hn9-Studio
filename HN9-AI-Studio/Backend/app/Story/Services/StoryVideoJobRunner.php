<?php

declare(strict_types=1);

namespace App\Story\Services;

use App\AI\Support\ProviderErrorSanitizer;
use App\Models\Project;
use App\Story\Contracts\LiveStoryVideoProviderAdapterInterface;
use App\Story\Contracts\StoryCapabilityRouterInterface;
use App\Story\Enums\StoryVideoErrorCode;
use App\Story\Enums\StoryVideoJobStatus;
use App\Story\Exceptions\StoryVideoEngineException;
use App\Story\Media\StoryMediaException;
use App\Story\Media\StoryMediaToolkit;
use App\Story\Models\StoryVideoGenerationJob;
use App\Story\Video\StoryVideoGenerationRequest;
use App\Story\Video\StoryVideoTimeoutPolicy;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Submit, poll and download steps for Story video jobs, shared by the request
 * path, the queue and provider callbacks. Only submit() reaches a
 * create-generation endpoint, and at most once per job; recovery continues
 * the stored operation id.
 *
 * A scene longer than one provider clip is a primary job (part 1) plus linked
 * part jobs. Parts are generated one after another and then joined into one
 * private file on the primary job, which is the only job the Studio reads.
 */
final readonly class StoryVideoJobRunner
{
    private const LOCK_SECONDS = 300;

    public function __construct(
        private StoryCapabilityRouterInterface $router,
        private StoryVideoTimeoutPolicy $policy,
        private StoryMediaToolkit $media,
    ) {}

    public function queued(): bool
    {
        return (bool) config('story_video.queue.enabled', false);
    }

    public function inFlight(StoryVideoGenerationJob $job): bool
    {
        return in_array($job->statusEnum(), [
            StoryVideoJobStatus::Queued,
            StoryVideoJobStatus::Submitted,
            StoryVideoJobStatus::Processing,
        ], true);
    }

    public function recovering(StoryVideoGenerationJob $job): bool
    {
        return $this->inFlight($job) && $this->pollFailures($job) > 0;
    }

    public function pollFailures(StoryVideoGenerationJob $job): int
    {
        return (int) ($job->provider_metadata['poll_failures'] ?? 0);
    }

    public function maxAttempts(): int
    {
        return max(1, $this->policy->maxAttempts);
    }

    public function isPart(StoryVideoGenerationJob $job): bool
    {
        return is_string($job->provider_metadata['primary_job'] ?? null);
    }

    /**
     * One queue step: an unclaimed job is submitted, anything else is advanced.
     */
    public function step(StoryVideoGenerationJob $job): StoryVideoGenerationJob
    {
        if ($this->isPart($job)) {
            return $job;
        }

        if ((string) $job->operation_id === '' && $job->started_at === null) {
            return $this->submit($job);
        }

        return $this->advance($job);
    }

    /**
     * The started_at claim is taken before the provider call and survives a
     * crash, so a claimed job is never submitted a second time.
     */
    public function submit(StoryVideoGenerationJob $job, ?StoryVideoGenerationRequest $request = null): StoryVideoGenerationJob
    {
        if ($this->isPart($job)) {
            return $job;
        }

        return $this->submitJob($job, $request);
    }

    /**
     * Continues the stored operation by one poll or download step. Never submits,
     * except the next part of a multi-part scene once the previous part is done.
     *
     * @throws StoryVideoEngineException when the step itself recorded a terminal failure
     */
    public function advance(StoryVideoGenerationJob $job): StoryVideoGenerationJob
    {
        if ($this->isPart($job)) {
            return $job;
        }

        $adapter = $this->adapter($job);
        if (! $adapter instanceof LiveStoryVideoProviderAdapterInterface || ! $this->inFlight($job)) {
            return $job;
        }

        if ((int) ($job->provider_metadata['unit_count'] ?? 1) > 1 && is_array($job->provider_metadata['unit_jobs'] ?? null)) {
            return $this->advanceParts($adapter, $job);
        }

        return $this->advanceSingle($adapter, $job);
    }

    private function submitJob(StoryVideoGenerationJob $job, ?StoryVideoGenerationRequest $request): StoryVideoGenerationJob
    {
        $adapter = $this->adapter($job);
        if (! $adapter instanceof LiveStoryVideoProviderAdapterInterface
            || (string) $job->operation_id !== ''
            || ! $this->inFlight($job)
        ) {
            return $job;
        }

        $claimed = StoryVideoGenerationJob::query()
            ->whereKey($job->id)
            ->whereNull('operation_id')
            ->whereNull('started_at')
            ->update(['started_at' => now()]);
        $job->refresh();
        if ($claimed !== 1) {
            return $job;
        }

        $request ??= StoryVideoGenerationRequest::fromArray((array) $job->request_payload);
        $outgoing = $this->withCallback($adapter, $job, $request);

        try {
            $submission = $adapter->submit($outgoing);
        } catch (StoryVideoEngineException $exception) {
            $job->forceFill([
                'status' => StoryVideoJobStatus::Failed->value,
                'error_code' => $exception->errorCode(),
                'error_message' => $this->safe($exception->getMessage()),
                'failed_at' => now(),
            ])->save();
            throw $exception;
        }

        $job->forceFill([
            'operation_id' => $submission->operationId,
            'model_key' => $submission->modelKey ?? $job->model_key,
            'status' => $submission->status->value,
            'request_payload' => $request->toArray(),
            'submitted_at' => now(),
        ])->save();

        return $job;
    }

    private function advanceSingle(LiveStoryVideoProviderAdapterInterface $adapter, StoryVideoGenerationJob $job): StoryVideoGenerationJob
    {
        if ((string) $job->operation_id === '') {
            return $this->expireUnconfirmedSubmit($job);
        }

        $lock = Cache::lock('story-video-job:'.$job->id, self::LOCK_SECONDS);
        if (! $lock->get()) {
            return $job;
        }

        try {
            return $this->poll($adapter, $job);
        } finally {
            $lock->release();
        }
    }

    private function advanceParts(LiveStoryVideoProviderAdapterInterface $adapter, StoryVideoGenerationJob $primary): StoryVideoGenerationJob
    {
        $lock = Cache::lock('story-video-parts:'.$primary->id, self::LOCK_SECONDS);
        if (! $lock->get()) {
            return $primary;
        }

        try {
            $outputs = (array) ($primary->provider_metadata['unit_outputs'] ?? []);
            $uuids = array_values(array_map('strval', (array) $primary->provider_metadata['unit_jobs']));
            $count = count($uuids);

            if (! isset($outputs['1'])) {
                $primary = $this->advanceSingle($adapter, $primary);
                $primary->refresh();
                $storage = $primary->provider_metadata['storage'] ?? null;
                if ($primary->statusEnum() !== StoryVideoJobStatus::Completed || ! is_array($storage)) {
                    return $primary;
                }
                $metadata = (array) $primary->provider_metadata;
                $metadata['unit_outputs'] = ['1' => $storage];
                unset($metadata['storage']);
                $primary->forceFill([
                    'status' => StoryVideoJobStatus::Processing->value,
                    'completed_at' => null,
                    'provider_metadata' => $metadata,
                ])->save();
                $outputs = $metadata['unit_outputs'];
            }

            for ($index = 1; $index < $count; $index++) {
                $number = (string) ($index + 1);
                if (isset($outputs[$number])) {
                    continue;
                }

                $part = StoryVideoGenerationJob::query()
                    ->where('uuid', $uuids[$index])
                    ->where('story_workspace_id', $primary->story_workspace_id)
                    ->first();
                if (! $part instanceof StoryVideoGenerationJob) {
                    return $this->failParts($primary, $index + 1, $count, StoryVideoErrorCode::UpstreamError->value, 'The part is missing.');
                }

                $partAdapter = $this->adapter($part);
                if (! $partAdapter instanceof LiveStoryVideoProviderAdapterInterface) {
                    return $this->failParts($primary, $index + 1, $count, StoryVideoErrorCode::GenerationNotEnabled->value, 'The video service is no longer connected.');
                }

                if ((string) $part->operation_id === '' && $part->started_at === null && $this->inFlight($part)) {
                    try {
                        $this->submitJob($part, null);
                    } catch (StoryVideoEngineException $exception) {
                        return $this->failParts($primary, $index + 1, $count, $exception->errorCode(), $exception->getMessage());
                    }

                    return $primary->refresh();
                }

                if ($this->inFlight($part)) {
                    try {
                        $part = $this->advanceSingle($partAdapter, $part);
                    } catch (StoryVideoEngineException) {
                        // The part recorded its own failure; it is read below.
                    }
                    $part->refresh();
                }

                $storage = $part->provider_metadata['storage'] ?? null;
                if ($part->statusEnum() === StoryVideoJobStatus::Completed && is_array($storage)) {
                    $outputs[$number] = $storage;
                    $metadata = (array) $primary->provider_metadata;
                    $metadata['unit_outputs'] = $outputs;
                    $primary->forceFill(['provider_metadata' => $metadata])->save();

                    continue;
                }

                if (! $this->inFlight($part)) {
                    return $this->failParts($primary, $index + 1, $count, (string) $part->error_code, $part->error_message);
                }

                return $primary->refresh();
            }

            return $this->joinParts($primary, $outputs, $count);
        } finally {
            $lock->release();
        }
    }

    /**
     * @param  array<string, mixed>  $outputs
     */
    private function joinParts(StoryVideoGenerationJob $primary, array $outputs, int $count): StoryVideoGenerationJob
    {
        $parts = [];
        for ($number = 1; $number <= $count; $number++) {
            $storage = $outputs[(string) $number] ?? null;
            if (! is_array($storage) || ! isset($storage['disk'], $storage['path'])) {
                return $this->failParts($primary, $number, $count, StoryVideoErrorCode::UpstreamError->value, 'The part file is missing.');
            }
            $parts[] = ['disk' => (string) $storage['disk'], 'path' => (string) $storage['path']];
        }

        $primary->loadMissing('workspace.project');
        $project = $primary->workspace?->project;
        if (! $project instanceof Project) {
            return $this->failParts($primary, 1, $count, StoryVideoErrorCode::UpstreamError->value, 'The project is missing.');
        }

        $payload = (array) $primary->request_payload;
        $target = (float) ($primary->provider_metadata['target_seconds'] ?? 0);

        try {
            $file = $this->media->join(
                $parts,
                $target > 0 ? $target : null,
                $project->uuid.'/'.Str::uuid()->toString().'.mp4',
                is_string($payload['aspect_ratio'] ?? null) ? $payload['aspect_ratio'] : '16:9',
            );
        } catch (StoryMediaException $exception) {
            $primary->forceFill([
                'status' => StoryVideoJobStatus::Failed->value,
                'error_code' => $exception->errorCode(),
                'error_message' => 'All '.$count.' parts were created, but they could not be joined. '.$exception->getMessage(),
                'failed_at' => now(),
            ])->save();

            return $primary;
        }

        $metadata = (array) $primary->provider_metadata;
        $metadata['storage'] = $file->toStorage();
        $metadata['joined_parts'] = $count;
        $primary->forceFill([
            'status' => StoryVideoJobStatus::Completed->value,
            'completed_at' => now(),
            'error_code' => null,
            'error_message' => null,
            'provider_metadata' => $metadata,
        ])->save();

        return $primary;
    }

    private function failParts(StoryVideoGenerationJob $primary, int $number, int $count, string $code, ?string $message): StoryVideoGenerationJob
    {
        $code = trim($code) !== '' ? $code : StoryVideoErrorCode::UpstreamError->value;
        $primary->forceFill([
            'status' => StoryVideoJobStatus::Failed->value,
            'error_code' => $code,
            'error_message' => $this->safe('Part '.$number.' of '.$count.' could not be created. '.(string) $message),
            'failed_at' => now(),
        ])->save();

        return $primary;
    }

    /**
     * Adds a one-time callback address for providers that report status by
     * webhook. Only a hash of the token is stored, and the callback is treated
     * as a hint to poll, never as the result itself.
     */
    private function withCallback(
        LiveStoryVideoProviderAdapterInterface $adapter,
        StoryVideoGenerationJob $job,
        StoryVideoGenerationRequest $request,
    ): StoryVideoGenerationRequest {
        $base = config('story_video.live_providers.'.$adapter->vendor().'.callback_base_url');
        if (! $adapter->supportsWebhook($request->capability) || ! is_string($base) || ! str_starts_with($base, 'https://')) {
            return $request;
        }

        $token = Str::random(48);
        $metadata = (array) $job->provider_metadata;
        $metadata['callback_token_hash'] = hash('sha256', $token);
        $job->forceFill(['provider_metadata' => $metadata])->save();

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
            preferredProvider: $request->preferredProvider,
            preferredModel: $request->preferredModel,
            idempotencyKey: $request->idempotencyKey,
            metadata: $request->metadata,
            extensions: array_merge($request->extensions, [
                'callback_url' => rtrim($base, '/').'/api/v1/story/video-callbacks/'.$job->uuid.'/'.$token,
            ]),
        );
    }

    private function poll(LiveStoryVideoProviderAdapterInterface $adapter, StoryVideoGenerationJob $job): StoryVideoGenerationJob
    {
        try {
            $status = $adapter->status($job);
            $job->refresh();
            if ($status === StoryVideoJobStatus::Processing && ($job->provider_metadata['download_pending'] ?? false) === true) {
                $adapter->result($job);
                $job->refresh();
            }
        } catch (StoryVideoEngineException $exception) {
            $job->refresh();
            if (! $this->inFlight($job)) {
                throw $exception;
            }

            return $this->attemptFailed($job, $exception->errorCode(), $exception->getMessage());
        } catch (Throwable $exception) {
            $job->refresh();
            if (! $this->inFlight($job)) {
                throw $exception;
            }

            return $this->attemptFailed($job, StoryVideoErrorCode::UpstreamError->value, $exception->getMessage());
        }

        if ($status === StoryVideoJobStatus::Failed && $this->inFlight($job)) {
            return $this->attemptFailed($job, (string) $job->error_code, $job->error_message);
        }

        if ($this->pollFailures($job) > 0) {
            $job->forceFill([
                'provider_metadata' => array_merge((array) $job->provider_metadata, ['poll_failures' => 0]),
            ])->save();
        }

        return $job;
    }

    /**
     * retry_count is the lifetime count of failed attempts on this operation;
     * poll_failures is the consecutive run that the attempt limit applies to.
     */
    private function attemptFailed(StoryVideoGenerationJob $job, string $code, ?string $message): StoryVideoGenerationJob
    {
        $code = strtoupper(trim($code));
        if ($code === '') {
            $code = StoryVideoErrorCode::UpstreamError->value;
        }
        $message = $this->safe($message);
        $consecutive = $this->pollFailures($job) + 1;
        $retryable = $this->policy->isRetryable($code);
        $metadata = array_merge((array) $job->provider_metadata, ['poll_failures' => $consecutive]);

        Log::warning('Story video job attempt failed.', [
            'job' => $job->uuid,
            'error_code' => $code,
            'retryable' => $retryable,
            'attempt' => $consecutive,
            'max_attempts' => $this->maxAttempts(),
        ]);

        if ($retryable && $consecutive < $this->maxAttempts()) {
            $job->forceFill([
                'retry_count' => (int) $job->retry_count + 1,
                'error_code' => $code,
                'error_message' => $message,
                'provider_metadata' => $metadata,
            ])->save();

            return $job;
        }

        $job->forceFill([
            'status' => StoryVideoJobStatus::Failed->value,
            'retry_count' => (int) $job->retry_count + 1,
            'error_code' => $code,
            'error_message' => $retryable
                ? mb_substr('Stopped after '.$consecutive.' attempts. '.$message, 0, 300)
                : $message,
            'failed_at' => now(),
            'provider_metadata' => $metadata,
        ])->save();

        return $job;
    }

    /**
     * A claimed submit with no stored operation id may or may not have reached
     * the provider. It is failed rather than sent again.
     */
    private function expireUnconfirmedSubmit(StoryVideoGenerationJob $job): StoryVideoGenerationJob
    {
        $claimedAt = $job->started_at;
        $staleAfter = $this->policy->connectTimeoutSeconds + $this->policy->requestTimeoutSeconds + 60;
        if ($claimedAt === null || $claimedAt->greaterThan(now()->subSeconds($staleAfter))) {
            return $job;
        }

        $job->forceFill([
            'status' => StoryVideoJobStatus::Failed->value,
            'error_code' => StoryVideoErrorCode::SubmissionUnconfirmed->value,
            'error_message' => 'The provider submission could not be confirmed. A new generation was not started.',
            'failed_at' => now(),
        ])->save();

        return $job;
    }

    private function safe(?string $message): string
    {
        $message = (string) preg_replace('#https?://\S+#i', '[redacted]', (string) $message);
        $message = (string) preg_replace('/(?:^|[?&\s])(?:key|api_key)=\S+/i', ' [redacted]', $message);

        return mb_substr(ProviderErrorSanitizer::message(trim($message), 'The video provider request failed.'), 0, 300);
    }

    private function adapter(StoryVideoGenerationJob $job): ?LiveStoryVideoProviderAdapterInterface
    {
        foreach ($this->router->adapters() as $candidate) {
            if ($candidate instanceof LiveStoryVideoProviderAdapterInterface && $candidate->key() === $job->provider_key) {
                return $candidate;
            }
        }

        return null;
    }
}
