<?php

declare(strict_types=1);

namespace App\AI\Factory;

use App\AI\Contracts\AIProviderInterface;
use App\AI\Contracts\ProviderFactoryInterface;
use App\AI\Contracts\ProviderRegistryInterface;
use App\AI\Exceptions\ProviderDisabledException;
use App\AI\Exceptions\ProviderNotConfiguredException;
use App\AI\Support\ProviderConfigResolver;

/**
 * Builds concrete providers from registry bindings (Factory pattern).
 *
 * Resolution is fully data-driven: the factory looks up the registered build
 * closure, resolves its configuration and invokes the closure. It contains no
 * hardcoded provider references, satisfying the Open/Closed principle — new
 * providers are added by registering, never by editing this class.
 */
final readonly class ProviderFactory implements ProviderFactoryInterface
{
    public function __construct(
        private ProviderRegistryInterface $registry,
        private ProviderConfigResolver $config,
    ) {}

    public function make(string $key): AIProviderInterface
    {
        $registration = $this->registry->get($key);

        if (! $registration->isEnabled()) {
            throw ProviderDisabledException::forKey($key);
        }

        $build = $registration->factory();

        return $build($this->config->resolve($key));
    }

    public function makeDefault(): AIProviderInterface
    {
        $key = $this->registry->defaultKey() ?? $this->config->defaultKey();

        if ($key === null) {
            throw ProviderNotConfiguredException::noDefault();
        }

        return $this->make($key);
    }
}
