<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Contracts\Execution\ExecutionTrackerInterface;
use App\Contracts\Logging\ActivityLoggerInterface;
use App\Contracts\Providers\ProviderRegistryInterface;
use App\Contracts\Services\AgentExecutionServiceInterface;
use App\Contracts\Services\AssetServiceInterface;
use App\Contracts\Services\ContentServiceInterface;
use App\Contracts\Services\GenerationRequestServiceInterface;
use App\Contracts\Services\DashboardServiceInterface;
use App\Contracts\Services\ExportServiceInterface;
use App\Contracts\Services\HealthServiceInterface;
use App\Contracts\Services\HistoryServiceInterface;
use App\Contracts\Services\ImageGenerationServiceInterface;
use App\Contracts\Services\ImageReviewServiceInterface;
use App\Contracts\Services\ImageServiceInterface;
use App\Contracts\Services\ProjectActivityServiceInterface;
use App\Contracts\Services\ProjectAssetServiceInterface;
use App\Contracts\Services\ProjectServiceInterface;
use App\Contracts\Services\VideoGenerationServiceInterface;
use App\Contracts\Services\VideoReviewServiceInterface;
use App\Contracts\Services\VideoServiceInterface;
use App\Contracts\Services\ScriptGenerationServiceInterface;
use App\Contracts\Services\ScriptReviewServiceInterface;
use App\Contracts\Services\ScriptServiceInterface;
use App\Contracts\Services\PromptServiceInterface;
use App\Contracts\Services\ProviderRegistryServiceInterface;
use App\Contracts\Services\UserServiceInterface;
use App\Contracts\Services\WorkflowServiceInterface;
use App\Contracts\Storage\StorageInterface;
use App\Story\Contracts\StoryBibleRepositoryInterface;
use App\Story\Contracts\StoryBibleServiceInterface;
use App\Story\Contracts\StoryCapabilityRouterInterface;
use App\Story\Contracts\StoryCharacterReferenceRepositoryInterface;
use App\Story\Contracts\StoryCharacterReferenceServiceInterface;
use App\Story\Contracts\StoryCharacterRepositoryInterface;
use App\Story\Contracts\StoryCharacterServiceInterface;
use App\Story\Contracts\StoryPlanRepositoryInterface;
use App\Story\Contracts\StoryPlanVersionRepositoryInterface;
use App\Story\Contracts\StoryPlannerServiceInterface;
use App\Story\Contracts\StoryStyleBibleRepositoryInterface;
use App\Story\Contracts\StoryStyleBibleServiceInterface;
use App\Story\Contracts\StoryStyleReferenceRepositoryInterface;
use App\Story\Contracts\StoryStyleReferenceServiceInterface;
use App\Story\Contracts\StoryVideoEngineInterface;
use App\Story\Contracts\StoryWorkspaceRepositoryInterface;
use App\Story\Contracts\StoryWorkspaceServiceInterface;
use App\Repositories\Contracts\ActivityLogRepositoryInterface;
use App\Repositories\Contracts\DashboardRepositoryInterface;
use App\Repositories\Contracts\ExecutionUsageRepositoryInterface;
use App\Repositories\Contracts\AgentExecutionRepositoryInterface;
use App\Repositories\Contracts\AssetRepositoryInterface;
use App\Repositories\Contracts\GeneratedContentRepositoryInterface;
use App\Repositories\Contracts\MediaFileRepositoryInterface;
use App\Repositories\Contracts\ProjectInputRepositoryInterface;
use App\Repositories\Contracts\ImageRepositoryInterface;
use App\Repositories\Contracts\ProjectAssetRepositoryInterface;
use App\Repositories\Contracts\ProjectRepositoryInterface;
use App\Repositories\Contracts\VideoReviewEventRepositoryInterface;
use App\Repositories\Contracts\VideoRepositoryInterface;
use App\Repositories\Contracts\ScriptRepositoryInterface;
use App\Repositories\Contracts\ScriptReviewEventRepositoryInterface;
use App\Repositories\Contracts\PromptExecutionRepositoryInterface;
use App\Repositories\Contracts\ProviderRepositoryInterface;
use App\Repositories\Contracts\UserRepositoryInterface;
use App\Repositories\Contracts\WorkflowRunRepositoryInterface;
use Tests\TestCase;

class ContainerBindingsTest extends TestCase
{
    /**
     * @return list<class-string>
     */
    public static function contracts(): array
    {
        return [
            ProjectRepositoryInterface::class,
            ScriptRepositoryInterface::class,
            ScriptReviewEventRepositoryInterface::class,
            ImageRepositoryInterface::class,
            VideoRepositoryInterface::class,
            VideoReviewEventRepositoryInterface::class,
            ProjectAssetRepositoryInterface::class,
            ProjectInputRepositoryInterface::class,
            AssetRepositoryInterface::class,
            ProviderRepositoryInterface::class,
            UserRepositoryInterface::class,
            GeneratedContentRepositoryInterface::class,
            WorkflowRunRepositoryInterface::class,
            ActivityLogRepositoryInterface::class,
            DashboardRepositoryInterface::class,
            ExecutionUsageRepositoryInterface::class,
            MediaFileRepositoryInterface::class,
            AgentExecutionRepositoryInterface::class,
            PromptExecutionRepositoryInterface::class,
            ActivityLoggerInterface::class,
            StorageInterface::class,
            ExecutionTrackerInterface::class,
            DashboardServiceInterface::class,
            ExportServiceInterface::class,
            ProjectServiceInterface::class,
            ScriptServiceInterface::class,
            ScriptGenerationServiceInterface::class,
            ScriptReviewServiceInterface::class,
            ImageServiceInterface::class,
            ImageGenerationServiceInterface::class,
            ImageReviewServiceInterface::class,
            VideoServiceInterface::class,
            VideoGenerationServiceInterface::class,
            VideoReviewServiceInterface::class,
            ProjectAssetServiceInterface::class,
            ProjectActivityServiceInterface::class,
            AssetServiceInterface::class,
            ContentServiceInterface::class,
            GenerationRequestServiceInterface::class,
            ProviderRegistryInterface::class,
            ProviderRegistryServiceInterface::class,
            UserServiceInterface::class,
            WorkflowServiceInterface::class,
            AgentExecutionServiceInterface::class,
            PromptServiceInterface::class,
            HistoryServiceInterface::class,
            HealthServiceInterface::class,
            StoryWorkspaceRepositoryInterface::class,
            StoryWorkspaceServiceInterface::class,
            StoryBibleRepositoryInterface::class,
            StoryBibleServiceInterface::class,
            StoryCharacterRepositoryInterface::class,
            StoryCharacterServiceInterface::class,
            StoryCharacterReferenceRepositoryInterface::class,
            StoryCharacterReferenceServiceInterface::class,
            StoryStyleBibleRepositoryInterface::class,
            StoryStyleBibleServiceInterface::class,
            StoryStyleReferenceRepositoryInterface::class,
            StoryStyleReferenceServiceInterface::class,
            StoryPlanRepositoryInterface::class,
            StoryPlanVersionRepositoryInterface::class,
            StoryPlannerServiceInterface::class,
            StoryCapabilityRouterInterface::class,
            StoryVideoEngineInterface::class,
        ];
    }

    /**
     * Every domain contract must resolve to a concrete implementation.
     */
    public function test_all_domain_contracts_are_bound(): void
    {
        foreach (self::contracts() as $contract) {
            $resolved = $this->app->make($contract);

            $this->assertInstanceOf($contract, $resolved, "{$contract} did not resolve to an implementation.");
        }
    }

    public function test_provider_registry_contracts_share_one_instance(): void
    {
        $read = $this->app->make(ProviderRegistryInterface::class);
        $manage = $this->app->make(ProviderRegistryServiceInterface::class);

        $this->assertSame($read, $manage);
    }
}
