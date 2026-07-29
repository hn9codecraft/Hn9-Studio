<?php

declare(strict_types=1);

namespace App\Providers;

use App\AI\Contracts\HealthManagerInterface;
use App\AI\Contracts\ProviderFactoryInterface;
use App\AI\Contracts\ProviderManagerInterface;
use App\AI\Contracts\ProviderRegistryInterface;
use App\AI\DTOs\ProviderCapabilityDTO;
use App\AI\DTOs\ProviderConfigDTO;
use App\AI\Factory\ProviderFactory;
use App\AI\Health\HealthManager;
use App\AI\Manager\ProviderManager;
use App\AI\Providers\Claude\ClaudeClient;
use App\AI\Providers\Claude\ClaudeConfig;
use App\AI\Providers\Claude\ClaudeModelRegistry;
use App\AI\Providers\Claude\ClaudeProvider;
use App\AI\Providers\Claude\ClaudeResponseNormalizer;
use App\AI\Providers\Claude\ClaudeTokenCounter;
use App\AI\Providers\Claude\ClaudeUsageCalculator;
use App\AI\Providers\OpenAI\OpenAIClient;
use App\AI\Providers\OpenAI\OpenAIConfig;
use App\AI\Providers\OpenAI\OpenAIModelRegistry;
use App\AI\Providers\OpenAI\OpenAIProvider;
use App\AI\Providers\OpenAI\OpenAIResponseNormalizer;
use App\AI\Providers\OpenAI\OpenAITokenCounter;
use App\AI\Providers\OpenAI\OpenAIUsageCalculator;
use App\AI\Registry\ProviderRegistry;
use App\AI\Support\ProviderConfigResolver;
use Illuminate\Http\Client\Factory;
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
        /** @var ProviderConfigResolver $resolver */
        $resolver = $this->app->make(ProviderConfigResolver::class);
        $this->registerClaudeProvider($resolver);
        $settings = config('ai.providers.openai', []);

        if (! is_array($settings) || ! ($settings['enabled'] ?? false)) {
            return;
        }

        /** @var ProviderRegistryInterface $registry */
        $registry = $this->app->make(ProviderRegistryInterface::class);
        $config = OpenAIConfig::fromProviderConfig($resolver->resolve('openai'));
        $models = new OpenAIModelRegistry($config);

        $registry->register(
            'openai',
            fn (ProviderConfigDTO $providerConfig): OpenAIProvider => new OpenAIProvider(
                new OpenAIClient($this->app->make(Factory::class), OpenAIConfig::fromProviderConfig($providerConfig)),
                $models,
                new OpenAIUsageCalculator($config),
                new OpenAIResponseNormalizer(new OpenAIUsageCalculator($config)),
                new OpenAITokenCounter,
                $config,
            ),
            new ProviderCapabilityDTO(
                key: 'openai', name: 'OpenAI', version: OpenAIProvider::VERSION,
                text: true, image: true, streaming: $config->supportsStreaming,
                functionCalling: $config->supportsFunctionCalling, models: $models->all(),
            ),
            priority: (int) ($settings['priority'] ?? 100),
        );
    }

    private function registerClaudeProvider(ProviderConfigResolver $resolver): void
    {
        $settings = config('ai.providers.claude', []);
        if (! is_array($settings) || ! ($settings['enabled'] ?? false)) {
            return;
        }

        $config = ClaudeConfig::fromProviderConfig($resolver->resolve('claude'));
        $models = new ClaudeModelRegistry($config);
        /** @var ProviderRegistryInterface $registry */
        $registry = $this->app->make(ProviderRegistryInterface::class);
        $registry->register(
            'claude',
            fn (ProviderConfigDTO $providerConfig): ClaudeProvider => new ClaudeProvider(
                new ClaudeClient($this->app->make(Factory::class), ClaudeConfig::fromProviderConfig($providerConfig)),
                $models,
                new ClaudeUsageCalculator($config),
                new ClaudeResponseNormalizer(new ClaudeUsageCalculator($config)),
                new ClaudeTokenCounter,
                $config,
            ),
            new ProviderCapabilityDTO('claude', 'Claude', ClaudeProvider::VERSION, text: true, streaming: $config->supportsStreaming, functionCalling: $config->supportsFunctionCalling, models: $models->all()),
            priority: (int) ($settings['priority'] ?? 90),
        );
    }
}
