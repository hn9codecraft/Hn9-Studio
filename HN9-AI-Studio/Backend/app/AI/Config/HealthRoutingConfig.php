<?php

declare(strict_types=1);

namespace App\AI\Config;

use App\AI\Support\HealthStatus;

/**
 * How observed and probed health shape routing.
 *
 * A healthy provider routes normally, a degraded one is ranked behind its
 * healthy peers and an unavailable one is withheld. Recovery is automatic:
 * observed state is held only for {@see self::$recoverySeconds}, so an idle
 * provider returns to Unknown — and to the routing table — on its own.
 */
final readonly class HealthRoutingConfig
{
    public function __construct(
        public bool $enabled = true,
        public bool $probe = false,
        public bool $excludeUnavailable = true,
        public bool $excludeUnknown = false,
        public bool $demoteDegraded = true,
        public int $degradedThreshold = 1,
        public int $unavailableThreshold = 3,
        public int $recoverySeconds = 300,
    ) {}

    public static function fromReader(ConfigReader $reader): self
    {
        return new self(
            enabled: $reader->bool('enabled', true),
            probe: $reader->bool('probe', false),
            excludeUnavailable: $reader->bool('exclude_unavailable', true),
            excludeUnknown: $reader->bool('exclude_unknown', false),
            demoteDegraded: $reader->bool('demote_degraded', true),
            degradedThreshold: max(1, $reader->int('degraded_threshold', 1)),
            unavailableThreshold: max(1, $reader->int('unavailable_threshold', 3)),
            recoverySeconds: max(1, $reader->int('recovery_seconds', 300)),
        );
    }

    /**
     * Whether a provider in this state may be routed to at all.
     */
    public function admits(HealthStatus $status): bool
    {
        return match ($status) {
            HealthStatus::Unavailable => ! $this->excludeUnavailable,
            HealthStatus::Unknown => ! $this->excludeUnknown,
            default => true,
        };
    }

    /**
     * The routing tier a status places a provider in; lower ranks first.
     *
     * Health is a tier rather than a score adjustment because scores are
     * normalised across the candidate set: with two candidates the better one
     * always scores 1.0 and the other 0.0, so no numeric penalty could reliably
     * express "behind its healthy peers". A tier can.
     */
    public function tierFor(HealthStatus $status): int
    {
        if (! $this->enabled || ! $this->demoteDegraded) {
            return 0;
        }

        return match ($status) {
            HealthStatus::Healthy, HealthStatus::Unknown => 0,
            HealthStatus::Degraded => 1,
            HealthStatus::Unavailable => 2,
        };
    }

    /**
     * The status implied by a run of consecutive failures.
     */
    public function statusForFailures(int $failures): HealthStatus
    {
        if ($failures >= $this->unavailableThreshold) {
            return HealthStatus::Unavailable;
        }

        return $failures >= $this->degradedThreshold ? HealthStatus::Degraded : HealthStatus::Healthy;
    }
}
