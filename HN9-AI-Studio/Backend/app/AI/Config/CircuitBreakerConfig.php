<?php

declare(strict_types=1);

namespace App\AI\Config;

use Throwable;

/**
 * Circuit breaker thresholds and storage.
 *
 * `trip_on` is deliberately narrower than the retryable set: a request the
 * provider correctly refused (an unsupported capability, say) says nothing
 * about the provider's health and must not open its circuit.
 */
final readonly class CircuitBreakerConfig
{
    /**
     * @param  list<class-string>  $tripOn  Failures that count against a provider.
     */
    public function __construct(
        public bool $enabled = true,
        public int $failureThreshold = 5,
        public int $successThreshold = 2,
        public int $recoveryTimeout = 60,
        public ?string $store = null,
        public string $prefix = 'ai:circuit',
        public array $tripOn = [],
    ) {}

    public static function fromReader(ConfigReader $reader): self
    {
        return new self(
            enabled: $reader->bool('enabled', true),
            failureThreshold: max(1, $reader->int('failure_threshold', 5)),
            successThreshold: max(1, $reader->int('success_threshold', 2)),
            recoveryTimeout: max(1, $reader->int('recovery_timeout', 60)),
            store: $reader->nullableString('store'),
            prefix: $reader->string('prefix', 'ai:circuit'),
            tripOn: $reader->classList('trip_on'),
        );
    }

    /**
     * Whether this failure counts against the provider's circuit.
     */
    public function trips(Throwable $failure): bool
    {
        foreach ($this->tripOn as $class) {
            if ($failure instanceof $class) {
                return true;
            }
        }

        return false;
    }

    /**
     * How long circuit state is retained. State lives slightly beyond the
     * recovery window so a half-open trial is not lost to expiry mid-probe.
     */
    public function stateTtl(): int
    {
        return max($this->recoveryTimeout * 2, $this->recoveryTimeout + 60);
    }
}
