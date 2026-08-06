<?php

declare(strict_types=1);

namespace App\AI\Config;

/**
 * Cache settings for the platform's four caches: built provider instances and
 * their metadata (process-lifetime, allocation-avoiding) plus health probes and
 * cost estimates (store-backed, request-spanning).
 */
final readonly class CacheConfig
{
    public function __construct(
        public ?string $store = null,
        public string $prefix = 'ai',
        public bool $providerInstances = true,
        public int $healthTtl = 60,
        public int $metadataTtl = 600,
        public int $costTtl = 300,
    ) {}

    public static function fromReader(ConfigReader $reader, int $healthTtl): self
    {
        $ttl = $reader->section('ttl');

        return new self(
            store: $reader->nullableString('store'),
            prefix: $reader->string('prefix', 'ai'),
            providerInstances: $reader->bool('providers', true),
            healthTtl: max(1, $healthTtl),
            metadataTtl: max(1, $ttl->int('metadata', 600)),
            costTtl: max(1, $ttl->int('cost', 300)),
        );
    }

    /**
     * A namespaced cache key, so every platform cache shares one prefix.
     */
    public function key(string ...$segments): string
    {
        return $this->prefix.':'.implode(':', $segments);
    }
}
