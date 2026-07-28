<?php

declare(strict_types=1);

namespace App\AI\Registry;

use App\AI\Contracts\AIProviderInterface;
use App\AI\DTOs\ProviderCapabilityDTO;
use App\AI\DTOs\ProviderConfigDTO;
use Closure;

/**
 * A single entry in the {@see ProviderRegistry}. Binds a provider key to the
 * closure that builds its {@see AIProviderInterface} instance, together with
 * its declared capabilities, routing priority and enabled flag.
 *
 * The build closure is invoked lazily by the factory — registering a provider
 * neither instantiates it nor calls any API.
 */
final class ProviderRegistration
{
    /**
     * @param  Closure(ProviderConfigDTO): AIProviderInterface  $factory
     */
    public function __construct(
        private readonly string $key,
        private readonly Closure $factory,
        private readonly ProviderCapabilityDTO $capabilities,
        private readonly int $priority = 0,
        private bool $enabled = true,
    ) {}

    public function key(): string
    {
        return $this->key;
    }

    /**
     * @return Closure(ProviderConfigDTO): AIProviderInterface
     */
    public function factory(): Closure
    {
        return $this->factory;
    }

    public function capabilities(): ProviderCapabilityDTO
    {
        return $this->capabilities;
    }

    public function priority(): int
    {
        return $this->priority;
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function enable(): void
    {
        $this->enabled = true;
    }

    public function disable(): void
    {
        $this->enabled = false;
    }
}
