<?php

declare(strict_types=1);

namespace App\Story\Video;

/**
 * Provider-neutral timeout/retry policy. Reuses concepts from AI retry config
 * without creating a second conflicting orchestration path for accepted jobs.
 */
final readonly class StoryVideoTimeoutPolicy
{
    public function __construct(
        public int $connectTimeoutSeconds = 10,
        public int $requestTimeoutSeconds = 60,
        public int $totalGenerationDeadlineSeconds = 900,
        public int $maxAttempts = 3,
        public int $backoffMs = 500,
        public int $jitterMs = 100,
        /** @var list<string> */
        public array $retryableErrors = [
            'PROVIDER_UNAVAILABLE',
            'RATE_LIMITED',
            'TIMEOUT',
            'UPSTREAM_ERROR',
        ],
        /** @var list<string> */
        public array $nonRetryableErrors = [
            'AUTHENTICATION_FAILED',
            'INVALID_INPUT',
            'CAPABILITY_UNSUPPORTED',
            'QUOTA_EXCEEDED',
            'INVALID_PROVIDER_RESPONSE',
        ],
    ) {}

    public function isRetryable(string $errorCode): bool
    {
        if (in_array($errorCode, $this->nonRetryableErrors, true)) {
            return false;
        }

        return in_array($errorCode, $this->retryableErrors, true);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'connect_timeout_seconds' => $this->connectTimeoutSeconds,
            'request_timeout_seconds' => $this->requestTimeoutSeconds,
            'total_generation_deadline_seconds' => $this->totalGenerationDeadlineSeconds,
            'max_attempts' => $this->maxAttempts,
            'backoff_ms' => $this->backoffMs,
            'jitter_ms' => $this->jitterMs,
            'retryable_errors' => $this->retryableErrors,
            'non_retryable_errors' => $this->nonRetryableErrors,
        ];
    }
}
