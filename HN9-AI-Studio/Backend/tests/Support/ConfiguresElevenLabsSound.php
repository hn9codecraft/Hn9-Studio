<?php

declare(strict_types=1);

namespace Tests\Support;

use App\AI\Cache\ProviderInstanceCache;
use App\AI\Cache\ProviderMetadataCache;
use App\AI\Config\PlatformConfig;
use App\AI\Contracts\CircuitBreakerInterface;
use App\AI\Contracts\HealthTrackerInterface;
use App\AI\Contracts\MetricsCollectorInterface;
use App\AI\Contracts\RetryPolicyInterface;
use App\AI\Execution\ModalityInvokerRegistry;
use App\AI\Routing\CostEstimator;
use App\AI\Routing\RoutingStrategyRegistry;
use App\Providers\AIServiceProvider;
use App\Story\Contracts\StoryCapabilityRouterInterface;
use App\Story\Contracts\StoryVideoEngineInterface;

/**
 * Connects the shared ElevenLabs provider with fake credentials for Creative Studio
 * sound tests. Every request must be answered by Http::fake(); nothing reaches the vendor.
 */
trait ConfiguresElevenLabsSound
{
    protected const ELEVENLABS_SPEECH_URL = 'https://api.elevenlabs.io/v1/text-to-speech/*';

    protected const ELEVENLABS_TEST_KEY = 'test-elevenlabs-key';

    /**
     * @param  array<string, mixed>  $overrides
     * @param  array<string, mixed>  $story
     */
    protected function enableElevenLabsSound(array $overrides = [], array $story = []): void
    {
        config([
            'ai.providers.elevenlabs' => [
                'enabled' => true,
                'api_key' => self::ELEVENLABS_TEST_KEY,
                'base_url' => 'https://api.elevenlabs.io/v1',
                'default_model' => 'model-standard',
                'timeout' => 5,
                'max_retries' => 0,
                'models' => ['model-standard'],
                'voices' => ['Rachel' => 'voice-id-one', 'Adam' => 'voice-id-two'],
                'default_voice' => 'Rachel',
                'output_format' => 'mp3_44100_128',
                'output_formats' => ['mp3_44100_128'],
                ...$overrides,
            ],
            'ai.retry.delay_ms' => 0,
            'ai.retry.jitter' => false,
            ...collect($story)->mapWithKeys(
                static fn ($value, string $key): array => ['story_video.audio_providers.elevenlabs.'.$key => $value],
            )->all(),
        ]);

        $this->rebuildSoundPlatform();
        (new AIServiceProvider($this->app))->boot();
    }

    protected function disableElevenLabsSound(): void
    {
        config(['ai.providers.elevenlabs.enabled' => false, 'ai.providers.elevenlabs.api_key' => null]);
        $this->rebuildSoundPlatform();
    }

    protected function rebuildSoundPlatform(): void
    {
        foreach ([
            PlatformConfig::class,
            ProviderInstanceCache::class,
            ProviderMetadataCache::class,
            HealthTrackerInterface::class,
            CircuitBreakerInterface::class,
            RetryPolicyInterface::class,
            MetricsCollectorInterface::class,
            RoutingStrategyRegistry::class,
            CostEstimator::class,
            ModalityInvokerRegistry::class,
            StoryCapabilityRouterInterface::class,
            StoryVideoEngineInterface::class,
        ] as $abstract) {
            $this->app->forgetInstance($abstract);
        }
    }
}
