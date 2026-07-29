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
use App\AI\Providers\Gemini\GeminiClient;
use App\AI\Providers\Gemini\GeminiConfig;
use App\AI\Providers\Gemini\GeminiModelRegistry;
use App\AI\Providers\Gemini\GeminiProvider;
use App\AI\Providers\Gemini\GeminiResponseNormalizer;
use App\AI\Providers\Gemini\GeminiTokenCounter;
use App\AI\Providers\Gemini\GeminiUsageCalculator;
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
 * concrete providers plug in here — and only here — without the manager,
 * factory or registry gaining provider-specific knowledge.
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
        $this->registerOpenAIProvider($resolver);
        $this->registerGeminiProvider($resolver);
    }

    private function registerClaudeProvider(ProviderConfigResolver $resolver): void
    {
        $settings = $this->settingsFor('claude');

        if ($settings === null) {
            return;
        }

        $config = ClaudeConfig::fromProviderConfig($resolver->resolve('claude'));
        $models = new ClaudeModelRegistry($config);

        $this->registry()->register(
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

    private function registerOpenAIProvider(ProviderConfigResolver $resolver): void
    {
        $settings = $this->settingsFor('openai');

        if ($settings === null) {
            return;
        }

        $config = OpenAIConfig::fromProviderConfig($resolver->resolve('openai'));
        $models = new OpenAIModelRegistry($config);

        $this->registry()->register(
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

    private function registerGeminiProvider(ProviderConfigResolver $resolver): void
    {
        $settings = $this->settingsFor('gemini');

        if ($settings === null) {
            return;
        }

        $config = GeminiConfig::fromProviderConfig($resolver->resolve('gemini'));
        $models = new GeminiModelRegistry($config);

        $this->registry()->register(
            'gemini',
            fn (ProviderConfigDTO $providerConfig): GeminiProvider => $this->makeGeminiProvider(
                GeminiConfig::fromProviderConfig($providerConfig),
            ),
            new ProviderCapabilityDTO(
                key: 'gemini', name: 'Gemini', version: GeminiProvider::VERSION,
                // Image support is declared only when image-capable models are configured.
                text: true, image: $config->imageModels !== [], streaming: $config->supportsStreaming,
                functionCalling: $config->supportsFunctionCalling, models: $models->all(),
            ),
            priority: (int) ($settings['priority'] ?? 80),
        );
    }

    private function makeGeminiProvider(GeminiConfig $config): GeminiProvider
    {
        $client = new GeminiClient($this->app->make(Factory::class), $config);
        $usage = new GeminiUsageCalculator($config);
        $normalizer = new GeminiResponseNormalizer($usage);

        return new GeminiProvider(
            $client,
            new GeminiModelRegistry($config),
            $usage,
            $normalizer,
            new GeminiTokenCounter($client, $normalizer, $config),
            $config,
        );
    }

    /**
     * The provider's configuration block, or null when it is absent or disabled.
     *
     * @return array<string, mixed>|null
     */
    private function settingsFor(string $key): ?array
    {
        $settings = config("ai.providers.{$key}", []);

        if (! is_array($settings) || ! ($settings['enabled'] ?? false)) {
            return null;
        }

        return $settings;
    }

    private function registry(): ProviderRegistryInterface
    {
        /** @var ProviderRegistryInterface $registry */
        $registry = $this->app->make(ProviderRegistryInterface::class);

        return $registry;
    }
}
