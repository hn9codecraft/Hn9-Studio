<?php

declare(strict_types=1);

namespace App\Providers;

use App\Story\Contracts\StoryBibleRepositoryInterface;
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
use App\Story\Video\StoryCapabilityRouter;
use App\Story\Video\StoryVideoCatalogFactory;
use App\Story\Video\StoryVideoEngine;
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
}
