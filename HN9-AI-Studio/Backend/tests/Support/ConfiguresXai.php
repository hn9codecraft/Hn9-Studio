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
 * Turns the xAI adapters on with a fake key. Call Http::fake() before any request.
 */
trait ConfiguresXai
{
    protected const XAI_TEST_KEY = 'xai-test-key-1234';

    /**
     * @param  array<string, mixed>  $extra
     */
    protected function enableXai(array $extra = []): void
    {
        config([
            'ai.providers.xai.enabled' => true,
            'ai.providers.xai.api_key' => self::XAI_TEST_KEY,
            'ai.providers.xai.base_url' => 'https://api.x.ai/v1',
            'ai.providers.xai.default_model' => 'grok-4.3',
            'ai.providers.xai.models' => ['grok-4.3'],
            'ai.providers.xai.image_models' => ['grok-imagine-image', 'grok-imagine-image-2.0'],
            'ai.providers.xai.image_default_model' => 'grok-imagine-image',
            'ai.providers.xai.timeout' => 5,
            'ai.providers.xai.max_retries' => 0,
            'ai.retry.delay_ms' => 0,
            'ai.retry.jitter' => false,
            'ai.retry.max_attempts' => 1,
            'ai.routing.strategy' => 'priority',
            'story_video.live_providers.xai.enabled' => true,
            'story_video.live_providers.xai.api_key' => self::XAI_TEST_KEY,
            'story_video.live_providers.xai.base_url' => 'https://api.x.ai/v1',
            'story_video.live_providers.xai.model' => 'grok-imagine-video-1.5-lite',
            'story_video.live_providers.xai.key' => 'video.xai',
            'story_video.live_providers.xai.resolution' => '720p',
            'story_video.live_providers.runway.enabled' => false,
            'story_video.live_providers.luma.enabled' => false,
            'story_video.live_providers.seedance.enabled' => false,
            'story_video.real_provider.enabled' => false,
            'story_video.queue.enabled' => false,
            ...$extra,
        ]);

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
        (new AIServiceProvider($this->app))->boot();
    }
}
