<?php

declare(strict_types=1);

namespace App\Story\Services;

use App\AI\Support\ProviderErrorSanitizer;
use App\Story\Contracts\StoryCapabilityRouterInterface;
use App\Story\Contracts\StoryVideoProviderAdapterInterface;
use App\Story\Enums\StoryVideoErrorCode;
use App\Story\Enums\StoryVideoJobStatus;
use App\Story\Exceptions\StoryVideoEngineException;
use App\Story\Models\StoryVideoGenerationJob;
use App\Story\Video\StoryVideoGenerationRequest;
use App\Story\Video\StoryVideoTimeoutPolicy;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Submit, poll and download steps for Story video jobs, shared by the request
 * path and the queue. Only submit() reaches a create-generation endpoint, and
 * at most once per job; recovery continues the stored operation id.
 */
final readonly class StoryVideoJobRunner
{
    private const LOCK_SECONDS = 300;

    public function __construct(
        private StoryCapabilityRouterInterface $router,
        private StoryVideoTimeoutPolicy $policy,
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

    /**
     * One queue step: an unclaimed job is submitted, anything else is advanced.
     */
    public function step(StoryVideoGenerationJob $job): StoryVideoGenerationJob
    {
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
        $adapter = $this->adapter();
        if (! $adapter instanceof StoryVideoProviderAdapterInterface
            || $job->provider_key !== StoryVideoDispatchService::LIVE_PROVIDER_KEY
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

        try {
            $submission = $adapter->submit($request);
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

    /**
     * Continues the stored operation by one poll or download step. Never submits.
     *
     * @throws StoryVideoEngineException when the step itself recorded a terminal failure
     */
    public function advance(StoryVideoGenerationJob $job): StoryVideoGenerationJob
    {
        $adapter = $this->adapter();
        if (! $adapter instanceof StoryVideoProviderAdapterInterface
            || $job->provider_key !== StoryVideoDispatchService::LIVE_PROVIDER_KEY
            || ! $this->inFlight($job)
        ) {
            return $job;
        }

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

    private function poll(StoryVideoProviderAdapterInterface $adapter, StoryVideoGenerationJob $job): StoryVideoGenerationJob
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

    private function adapter(): ?StoryVideoProviderAdapterInterface
    {
        foreach ($this->router->adapters() as $candidate) {
            if ($candidate->key() === StoryVideoDispatchService::LIVE_PROVIDER_KEY) {
                return $candidate;
            }
        }

        return null;
    }
}
