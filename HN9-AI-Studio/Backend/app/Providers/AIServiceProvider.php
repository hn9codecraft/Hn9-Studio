<?php

declare(strict_types=1);

namespace App\Providers;

use App\AI\Contracts\HealthManagerInterface;
use App\AI\Contracts\ProviderFactoryInterface;
use App\AI\Contracts\ProviderManagerInterface;
use App\AI\Contracts\ProviderRegistryInterface;
use App\AI\Factory\ProviderFactory;
use App\AI\Health\HealthManager;
use App\AI\Manager\ProviderManager;
use App\AI\Registry\ProviderRegistry;
use Illuminate\Support\ServiceProvider;

/**
 * Wires the AI provider foundation (App\AI) into the container.
 *
 * The registry is a singleton so provider registrations persist for the
 * request/worker lifetime; the factory, health manager and manager are bound to
 * their contracts and resolved on demand. Every dependency is an interface, so
 * concrete providers (later sprints) plug in without touching this layer.
 */
class AIServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // The registry holds process-lifetime registrations → singleton.
        $this->app->singleton(ProviderRegistryInterface::class, ProviderRegistry::class);

        $this->app->bind(ProviderFactoryInterface::class, ProviderFactory::class);
        $this->app->bind(HealthManagerInterface::class, HealthManager::class);
        $this->app->bind(ProviderManagerInterface::class, ProviderManager::class);
    }

    public function boot(): void
    {
        // Extension point: concrete provider packages register themselves on
        // the ProviderRegistryInterface here in later sprints. No provider is
        // registered in Sprint 5.3.1.
    }
}
