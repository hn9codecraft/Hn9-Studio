<?php

declare(strict_types=1);

namespace App\AI\Resilience;

/**
 * The wall-clock budget for one dispatch, spanning every retry and every
 * fallback.
 *
 * Without it, a chain of four providers at three attempts each could stack a
 * dozen timeouts before answering. The deadline lets the platform stop early
 * and fail gracefully instead: callers ask whether the next wait or the next
 * provider still fits before committing to it.
 *
 * Time is read from `hrtime()`, so the deadline is unaffected by clock changes
 * and by test time travel.
 */
final readonly class Deadline
{
    private const NS_PER_MS = 1_000_000;

    private function __construct(private ?int $expiresAtNs) {}

    /**
     * A deadline this many milliseconds from now; null means unbounded.
     */
    public static function afterMilliseconds(?int $milliseconds): self
    {
        if ($milliseconds === null || $milliseconds <= 0) {
            return self::none();
        }

        return new self(hrtime(true) + $milliseconds * self::NS_PER_MS);
    }

    /**
     * An unbounded deadline.
     */
    public static function none(): self
    {
        return new self(null);
    }

    public function isBounded(): bool
    {
        return $this->expiresAtNs !== null;
    }

    /**
     * Milliseconds left, or null when unbounded. Never negative.
     */
    public function remainingMs(): ?int
    {
        if ($this->expiresAtNs === null) {
            return null;
        }

        return (int) max(0, intdiv($this->expiresAtNs - hrtime(true), self::NS_PER_MS));
    }

    public function exhausted(): bool
    {
        return $this->remainingMs() === 0;
    }

    /**
     * Whether an operation expected to take this long still fits.
     */
    public function allows(int $milliseconds): bool
    {
        $remaining = $this->remainingMs();

        return $remaining === null || $remaining > $milliseconds;
    }
}
