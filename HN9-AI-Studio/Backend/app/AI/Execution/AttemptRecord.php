<?php

declare(strict_types=1);

namespace App\AI\Execution;

/**
 * One entry in a dispatch's audit trail.
 *
 * Every provider touched leaves a record — including those never called,
 * because a skipped provider (open circuit, exhausted deadline) is exactly what
 * an operator needs to see when explaining why a request went where it did.
 */
final readonly class AttemptRecord
{
    public function __construct(
        public string $provider,
        public int $attempt,
        public bool $successful,
        public int $durationMs = 0,
        public ?string $error = null,
        public ?string $errorCode = null,
        public ?string $skipped = null,
    ) {}

    public static function success(string $provider, int $attempt, int $durationMs): self
    {
        return new self($provider, $attempt, true, $durationMs);
    }

    public static function failure(string $provider, int $attempt, int $durationMs, string $error, ?string $code = null): self
    {
        return new self($provider, $attempt, false, $durationMs, $error, $code);
    }

    /**
     * A provider that was planned but never called.
     */
    public static function skipped(string $provider, string $reason): self
    {
        return new self($provider, 0, false, skipped: $reason);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_filter([
            'provider' => $this->provider,
            'attempt' => $this->attempt,
            'successful' => $this->successful,
            'duration_ms' => $this->durationMs,
            'error' => $this->error,
            'error_code' => $this->errorCode,
            'skipped' => $this->skipped,
        ], static fn (mixed $value): bool => $value !== null);
    }
}
