<?php

declare(strict_types=1);

namespace App\Providers;

use App\AI\Providers\Gemini\GeminiClient;
use App\AI\Providers\Gemini\GeminiConfig;
use App\AI\Providers\Gemini\GeminiModelRegistry;
use App\AI\Providers\Gemini\GeminiProvider;
use App\AI\Providers\Gemini\GeminiResponseNormalizer;
use App\AI\Providers\Gemini\GeminiTokenCounter;
use App\AI\Providers\Gemini\GeminiUsageCalculator;
use App\AI\Support\ProviderConfigResolver;
use App\Services\VideoBinaryStore;
use App\Story\Contracts\StoryBibleRepositoryInterface;
use Illuminate\Http\Client\Factory as HttpFactory;
use App\Story\Contracts\StoryBibleServiceInterface;
use App\Story\Contracts\StoryCapabilityRouterInterface;
use App\Story\Contracts\StoryCharacterReferenceRepositoryInterface;
use App\Story\Contracts\StoryCharacterReferenceServiceInterface;
use App\Story\Contracts\StoryCharacterRepositoryInterface;
use App\Story\Contracts\StoryCharacterServiceInterface;
use App\Story\Contracts\StoryPlanMaterializerInterface;
use App\Story\Contracts\StoryPlanRepositoryInterface;
use App\Story\Contracts\StoryPlanVersionRepositoryInterface;
use App\Story\Contracts\StoryPlannerServiceInterface;
use App\Story\Contracts\StoryReelRepositoryInterface;
use App\Story\Contracts\StoryReelServiceInterface;
use App\Story\Contracts\StorySceneRepositoryInterface;
use App\Story\Contracts\StorySceneServiceInterface;
use App\Story\Contracts\StoryStyleBibleRepositoryInterface;
use App\Story\Contracts\StoryStyleBibleServiceInterface;
use App\Story\Contracts\StoryStyleReferenceRepositoryInterface;
use App\Story\Contracts\StoryStyleReferenceServiceInterface;
use App\Story\Contracts\StoryVideoEngineInterface;
use App\Story\Contracts\StoryWorkspaceRepositoryInterface;
use App\Story\Contracts\StoryWorkspaceServiceInterface;
use App\Story\Repositories\StoryBibleRepository;
use App\Story\Repositories\StoryCharacterReferenceRepository;
use App\Story\Repositories\StoryCharacterRepository;
use App\Story\Repositories\StoryPlanRepository;
use App\Story\Repositories\StoryPlanVersionRepository;
use App\Story\Repositories\StoryReelRepository;
use App\Story\Repositories\StorySceneRepository;
use App\Story\Repositories\StoryStyleBibleRepository;
use App\Story\Repositories\StoryStyleReferenceRepository;
use App\Story\Repositories\StoryWorkspaceRepository;
use App\Story\Services\StoryBibleService;
use App\Story\Services\StoryCharacterReferenceService;
use App\Story\Services\StoryCharacterService;
use App\Story\Services\StoryPlanMaterializer;
use App\Story\Services\StoryPlannerService;
use App\Story\Services\StoryReelService;
use App\Story\Services\StorySceneService;
use App\Story\Services\StoryStyleBibleService;
use App\Story\Services\StoryStyleReferenceService;
use App\Story\Services\StoryWorkspaceService;
use App\Story\Enums\StoryVideoAsyncMode;
use App\Story\Enums\StoryVideoCapability;
use App\Story\Video\Adapters\GeminiStoryVideoAdapter;
use App\Story\Video\CatalogStoryVideoAdapter;
use App\Story\Video\StoryCapabilityRouter;
use App\Story\Video\StoryVideoCatalogFactory;
use App\Story\Video\StoryVideoEngine;
use App\Story\Video\StoryVideoModelSpec;
use App\Story\Video\StoryVideoTimeoutPolicy;
use Illuminate\Support\ServiceProvider;

/**
 * Project Story module boundary. Planner/text and reference image generation
 * use shared ProviderDispatcherInterface — no vendor clients are bound here.
 */
class StoryServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(StoryCapabilityRouterInterface::class, function ($app) {
            $router = new StoryCapabilityRouter;
            $factory = $app->make(StoryVideoCatalogFactory::class);
            foreach ($factory->fromConfig((array) config('story_video.providers', [])) as $adapter) {
                $router->register($adapter);
            }

            if (self::realVideoProviderEnabled($app)) {
                $geminiConfig = GeminiConfig::fromProviderConfig(
                    $app->make(ProviderConfigResolver::class)->resolve('gemini'),
                );
                $client = new GeminiClient($app->make(HttpFactory::class), $geminiConfig);
                $usage = new GeminiUsageCalculator($geminiConfig);
                $normalizer = new GeminiResponseNormalizer($usage);
                $router->register(new GeminiStoryVideoAdapter(
                    self::liveCatalogAdapter(),
                    new GeminiProvider(
                        $client,
                        new GeminiModelRegistry($geminiConfig),
                        $usage,
                        $normalizer,
                        new GeminiTokenCounter($client, $normalizer, $geminiConfig),
                        $geminiConfig,
                    ),
                    $app->make(VideoBinaryStore::class),
                ));
            }

            return $router;
        });

        $this->app->singleton(StoryVideoTimeoutPolicy::class, function () {
            $cfg = (array) config('story_video.timeouts', []);

            return new StoryVideoTimeoutPolicy(
                connectTimeoutSeconds: (int) ($cfg['connect_timeout_seconds'] ?? 10),
                requestTimeoutSeconds: (int) ($cfg['request_timeout_seconds'] ?? 60),
                totalGenerationDeadlineSeconds: (int) ($cfg['total_generation_deadline_seconds'] ?? 900),
                maxAttempts: (int) ($cfg['max_attempts'] ?? 3),
                backoffMs: (int) ($cfg['backoff_ms'] ?? 500),
                jitterMs: (int) ($cfg['jitter_ms'] ?? 100),
            );
        });

        $this->app->singleton(StoryVideoEngineInterface::class, StoryVideoEngine::class);
        $this->app->bind(StoryWorkspaceRepositoryInterface::class, StoryWorkspaceRepository::class);
        $this->app->bind(StoryWorkspaceServiceInterface::class, StoryWorkspaceService::class);
        $this->app->bind(StoryBibleRepositoryInterface::class, StoryBibleRepository::class);
        $this->app->bind(StoryBibleServiceInterface::class, StoryBibleService::class);
        $this->app->bind(StoryCharacterRepositoryInterface::class, StoryCharacterRepository::class);
        $this->app->bind(StoryCharacterServiceInterface::class, StoryCharacterService::class);
        $this->app->bind(StoryCharacterReferenceRepositoryInterface::class, StoryCharacterReferenceRepository::class);
        $this->app->bind(StoryCharacterReferenceServiceInterface::class, StoryCharacterReferenceService::class);
        $this->app->bind(StoryStyleBibleRepositoryInterface::class, StoryStyleBibleRepository::class);
        $this->app->bind(StoryStyleBibleServiceInterface::class, StoryStyleBibleService::class);
        $this->app->bind(StoryStyleReferenceRepositoryInterface::class, StoryStyleReferenceRepository::class);
        $this->app->bind(StoryStyleReferenceServiceInterface::class, StoryStyleReferenceService::class);
        $this->app->bind(StoryPlanRepositoryInterface::class, StoryPlanRepository::class);
        $this->app->bind(StoryPlanVersionRepositoryInterface::class, StoryPlanVersionRepository::class);
        $this->app->bind(StoryPlannerServiceInterface::class, StoryPlannerService::class);
        $this->app->bind(StoryReelRepositoryInterface::class, StoryReelRepository::class);
        $this->app->bind(StoryReelServiceInterface::class, StoryReelService::class);
        $this->app->bind(StorySceneRepositoryInterface::class, StorySceneRepository::class);
        $this->app->bind(StorySceneServiceInterface::class, StorySceneService::class);
        $this->app->bind(StoryPlanMaterializerInterface::class, StoryPlanMaterializer::class);
    }

    private static function realVideoProviderEnabled(mixed $app): bool
    {
        $flag = config('story_video.real_provider.enabled');
        $gemini = (array) config('ai.providers.gemini', []);
        $ready = ($gemini['enabled'] ?? false) === true
            && is_string($gemini['api_key'] ?? null)
            && $gemini['api_key'] !== ''
            && is_array($gemini['video_models'] ?? null)
            && $gemini['video_models'] !== [];

        if ($flag === null) {
            return ! $app->environment('testing') && $ready;
        }

        return filter_var($flag, FILTER_VALIDATE_BOOLEAN) && $ready;
    }

    private static function liveCatalogAdapter(): CatalogStoryVideoAdapter
    {
        $durations = array_map('intval', (array) config('story_video.real_provider.durations', [8]));
        $capabilities = [
            StoryVideoCapability::TextToVideo,
            StoryVideoCapability::ImageToVideo,
            StoryVideoCapability::ReferenceToVideo,
        ];

        return new CatalogStoryVideoAdapter(
            adapterKey: (string) config('story_video.real_provider.key', 'video.live'),
            label: 'Video Provider',
            capabilities: $capabilities,
            enabled: true,
            available: true,
            priority: 200,
            durations: $durations,
            minDuration: min($durations),
            maxDuration: max($durations),
            aspectRatios: ['16:9', '9:16', '1:1'],
            resolutions: ['720p'],
            inputTypes: ['text', 'image', 'reference_image'],
            audio: false,
            mode: StoryVideoAsyncMode::AsyncPoll,
            polling: true,
            webhook: false,
            download: true,
            models: [
                new StoryVideoModelSpec(
                    providerKey: (string) config('story_video.real_provider.key', 'video.live'),
                    modelKey: (string) (config('ai.providers.gemini.video_default_model') ?: 'configured-video-model'),
                    displayName: 'Default video model',
                    capabilities: $capabilities,
                    enabled: true,
                    priority: 200,
                    durations: $durations,
                    aspectRatios: ['16:9', '9:16', '1:1'],
                    resolutions: ['720p'],
                    inputTypes: ['text', 'image', 'reference_image'],
                    audioSupported: false,
                ),
            ],
        );
    }
}
