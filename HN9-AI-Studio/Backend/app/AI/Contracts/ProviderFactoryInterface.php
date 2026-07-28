<?php

declare(strict_types=1);

namespace App\AI\Contracts;

use App\AI\Exceptions\ProviderDisabledException;
use App\AI\Exceptions\ProviderNotConfiguredException;
use App\AI\Exceptions\ProviderNotRegisteredException;

/**
 * Builds concrete {@see AIProviderInterface} instances from registry bindings,
 * injecting the resolved configuration. Providers are created lazily and only
 * on request — construction performs no API call.
 */
interface ProviderFactoryInterface
{
    /**
     * Build the provider registered under the given key.
     *
     * @throws ProviderNotRegisteredException
     * @throws ProviderDisabledException
     */
    public function make(string $key): AIProviderInterface;

    /**
     * Build the configured default provider.
     *
     * @throws ProviderNotConfiguredException
     */
    public function makeDefault(): AIProviderInterface;
}
