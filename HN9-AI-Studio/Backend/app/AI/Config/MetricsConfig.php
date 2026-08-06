<?php

declare(strict_types=1);

namespace App\AI\Config;

/**
 * Metrics collection settings. Disabling this swaps the collector for the null
 * implementation, so the hot path costs nothing rather than being branched
 * around at every call site.
 */
final readonly class MetricsConfig
{
    public function __construct(
        public bool $enabled = true,
        public ?string $store = null,
        public string $prefix = 'ai:metrics',
        public int $ttl = 86_400,
    ) {}

    public static function fromReader(ConfigReader $reader): self
    {
        return new self(
            enabled: $reader->bool('enabled', true),
            store: $reader->nullableString('store'),
            prefix: $reader->string('prefix', 'ai:metrics'),
            ttl: max(60, $reader->int('ttl', 86_400)),
        );
    }
}
