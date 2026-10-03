<?php

declare(strict_types=1);

namespace App\Providers;

use App\AI\Contracts\ProviderDispatcherInterface;
use App\AI\Contracts\ProviderRegistryInterface;
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
use App\Story\Contracts\StoryBibleServiceInterface;
use App\Story\Contracts\StoryCapabilityRouterInterface;
use App\Story\Contracts\StoryCharacterReferenceRepositoryInterface;
use App\Story\Contracts\StoryCharacterReferenceServiceInterface;
use App\Story\Contracts\StoryCharacterRepositoryInterface;
use App\Story\Contracts\StoryCharacterServiceInterface;
use App\Story\Contracts\StoryPlanApprovalServiceInterface;
use App\Story\Contracts\StoryPlanMaterializerInterface;
use App\Story\Contracts\StoryPlannerServiceInterface;
use App\Story\Contracts\StoryPlanRepositoryInterface;
use App\Story\Contracts\StoryPlanVersionRepositoryInterface;
use App\Story\Contracts\StoryProductionPlanServiceInterface;
use App\Story\Contracts\StoryProductionUnitGenerationServiceInterface;
use App\Story\Contracts\StoryProductionUnitVersionServiceInterface;
use App\Story\Contracts\StoryReelRepositoryInterface;
use App\Story\Contracts\StoryReelServiceInterface;
use App\Story\Contracts\StorySceneAssemblyServiceInterface;
use App\Story\Contracts\StorySceneRepositoryInterface;
use App\Story\Contracts\StorySceneServiceInterface;
use App\Story\Contracts\StoryStyleBibleRepositoryInterface;
use App\Story\Contracts\StoryStyleBibleServiceInterface;
use App\Story\Contracts\StoryStyleReferenceRepositoryInterface;
use App\Story\Contracts\StoryStyleReferenceServiceInterface;
use App\Story\Contracts\StoryVideoEngineInterface;
use App\Story\Contracts\StoryWorkspaceRepositoryInterface;
use App\Story\Contracts\StoryWorkspaceServiceInterface;
use App\Story\Enums\StoryAudioRole;
use App\Story\Enums\StoryVideoAsyncMode;
use App\Story\Enums\StoryVideoCapability;
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
use App\Story\Services\StoryPlanApprovalService;
use App\Story\Services\StoryPlanMaterializer;
use App\Story\Services\StoryPlannerService;
use App\Story\Services\StoryProductionPlanService;
use App\Story\Services\StoryProductionUnitGenerationService;
use App\Story\Services\StoryProductionUnitVersionService;
use App\Story\Services\StoryReelService;
use App\Story\Services\StorySceneAssemblyService;
use App\Story\Services\StorySceneService;
use App\Story\Services\StoryStyleBibleService;
use App\Story\Services\StoryStyleReferenceService;
use App\Story\Services\StoryWorkspaceService;
use App\Story\Video\Adapters\ElevenLabsStoryAudioAdapter;
use App\Story\Video\Adapters\GeminiStoryVideoAdapter;
use App\Story\Video\Adapters\LiveStoryVideoAdapterFactory;
use App\Story\Video\CatalogStoryVideoAdapter;
use App\Story\Video\StoryCapabilityRouter;
use App\Story\Video\StoryVideoCatalogFactory;
use App\Story\Video\StoryVideoEngine;
use App\Story\Video\StoryVideoModelSpec;
use App\Story\Video\StoryVideoTimeoutPolicy;
use Illuminate\Http\Client\Factory as HttpFactory;
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

            $live = $app->make(LiveStoryVideoAdapterFactory::class)->fromConfig(
                (array) config('story_video.live_providers', []),
                $app->environment('testing'),
            );
            foreach ($live as $adapter) {
                $router->register($adapter);
            }

            $sound = new ElevenLabsStoryAudioAdapter(
                self::soundCatalogAdapter((array) config('story_video.audio_providers.elevenlabs', [])),
                $app->make(ProviderDispatcherInterface::class),
                $app->make(ProviderRegistryInterface::class),
                $app->make(ProviderConfigResolver::class),
                (array) config('story_video.audio_providers.elevenlabs', []),
            );
            if ($sound->enabled()) {
                $router->register($sound);
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
        $this->app->bind(StoryProductionPlanServiceInterface::class, StoryProductionPlanService::class);
        $this->app->bind(StoryPlanApprovalServiceInterface::class, StoryPlanApprovalService::class);
        $this->app->bind(StoryProductionUnitGenerationServiceInterface::class, StoryProductionUnitGenerationService::class);
        $this->app->bind(StoryProductionUnitVersionServiceInterface::class, StoryProductionUnitVersionService::class);
        $this->app->bind(StorySceneAssemblyServiceInterface::class, StorySceneAssemblyService::class);
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

    /**
     * Capability description for scene sound. Speech length follows the text, so a
     * single nominal unit keeps the duration planner from splitting it.
     *
     * @param  array<string, mixed>  $config
     */
    private static function soundCatalogAdapter(array $config): CatalogStoryVideoAdapter
    {
        $key = (string) ($config['key'] ?? 'audio.elevenlabs');
        $priority = (int) ($config['priority'] ?? 260);
        $roles = array_values(array_intersect(
            array_map('strval', (array) ($config['roles'] ?? [])),
            StoryAudioRole::values(),
        ));
        $model = (string) (config('ai.providers.elevenlabs.default_model') ?: 'configured-voice-model');

        return new CatalogStoryVideoAdapter(
            adapterKey: $key,
            label: (string) ($config['label'] ?? 'Sound service'),
            capabilities: [StoryVideoCapability::Audio],
            enabled: true,
            available: true,
            priority: $priority,
            durations: [8],
            minDuration: 8,
            maxDuration: 8,
            aspectRatios: [],
            resolutions: [],
            inputTypes: ['text'],
            audio: true,
            mode: StoryVideoAsyncMode::Sync,
            polling: true,
            webhook: false,
            download: true,
            models: [
                new StoryVideoModelSpec(
                    providerKey: $key,
                    modelKey: $model,
                    displayName: 'Default voice model',
                    capabilities: [StoryVideoCapability::Audio],
                    enabled: true,
                    priority: $priority,
                    durations: [8],
                    aspectRatios: [],
                    resolutions: [],
                    inputTypes: ['text'],
                    audioSupported: true,
                ),
            ],
            audioRoles: $roles,
        );
    }

    private static function liveCatalogAdapter(): CatalogStoryVideoAdapter
    {
        $durations = array_map('intval', (array) config('story_video.real_provider.durations', [8]));
        $capabilities = [
            StoryVideoCapability::TextToVideo,
            StoryVideoCapability::ImageToVideo,
            StoryVideoCapability::ReferenceToVideo,
            StoryVideoCapability::VideoEdit,
            StoryVideoCapability::VideoExtend,
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
            inputTypes: ['text', 'image', 'reference_image', 'video'],
            audio: true,
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
                    inputTypes: ['text', 'image', 'reference_image', 'video'],
                    audioSupported: true,
                ),
            ],
        );
    }
}
