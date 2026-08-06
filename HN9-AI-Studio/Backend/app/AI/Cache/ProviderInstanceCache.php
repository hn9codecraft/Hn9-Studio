<?php

declare(strict_types=1);

namespace App\AI\Cache;

use App\AI\Contracts\AIProviderInterface;
use App\AI\Contracts\ProviderFactoryInterface;
use App\AI\Contracts\ProviderRegistryInterface;

/**
 * Memoises built provider instances for the request/worker lifetime.
 *
 * Resolving a provider builds a client, a model registry, a usage calculator, a
 * normaliser and a token counter. Routing may consult several providers per
 * request — for a cost estimate, a model list or a fallback attempt — so
 * rebuilding that graph each time is pure waste. Construction performs no I/O,
 * which is exactly why it is safe to hold on to the result.
 *
 * This decorates the factory's *use*, not the factory itself: the build path
 * remains {@see ProviderFactoryInterface::make()} and the disabled-provider
 * guard remains the factory's, so a provider disabled after being cached is
 * dropped and re-resolved through the canonical path.
 */
final class ProviderInstanceCache
{
    /**
     * @var array<string, AIProviderInterface>
     */
    private array $instances = [];

    public function __construct(
        private readonly ProviderFactoryInterface $factory,
        private readonly ProviderRegistryInterface $registry,
        private readonly bool $enabled = true,
    ) {}

    /**
     * The provider for a key, built on first use.
     */
    public function get(string $key): AIProviderInterface
    {
        if (! $this->enabled) {
            return $this->factory->make($key);
        }

        if (isset($this->instances[$key]) && $this->stillEnabled($key)) {
            return $this->instances[$key];
        }

        unset($this->instances[$key]);

        return $this->instances[$key] = $this->factory->make($key);
    }

    public function has(string $key): bool
    {
        return isset($this->instances[$key]);
    }

    public function forget(string $key): void
    {
        unset($this->instances[$key]);
    }

    public function flush(): void
    {
        $this->instances = [];
    }

    /**
     * Keys currently held, for diagnostics.
     *
     * @return list<string>
     */
    public function keys(): array
    {
        return array_keys($this->instances);
    }

    /**
     * A cached instance is only reusable while its registration is still
     * enabled; otherwise the factory must be allowed to raise.
     */
    private function stillEnabled(string $key): bool
    {
        return $this->registry->has($key) && $this->registry->get($key)->isEnabled();
    }
}
