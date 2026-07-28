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
use App\Contracts\Services\HealthServiceInterface;
use App\Contracts\Services\HistoryServiceInterface;
use App\Contracts\Services\ProjectServiceInterface;
use App\Contracts\Services\PromptServiceInterface;
use App\Contracts\Services\ProviderRegistryServiceInterface;
use App\Contracts\Services\WorkflowServiceInterface;
use App\Contracts\Storage\StorageInterface;
use App\Repositories\Contracts\ActivityLogRepositoryInterface;
use App\Repositories\Contracts\AgentExecutionRepositoryInterface;
use App\Repositories\Contracts\AssetRepositoryInterface;
use App\Repositories\Contracts\GeneratedContentRepositoryInterface;
use App\Repositories\Contracts\MediaFileRepositoryInterface;
use App\Repositories\Contracts\ProjectInputRepositoryInterface;
use App\Repositories\Contracts\ProjectRepositoryInterface;
use App\Repositories\Contracts\PromptExecutionRepositoryInterface;
use App\Repositories\Contracts\ProviderRepositoryInterface;
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
            ProjectInputRepositoryInterface::class,
            AssetRepositoryInterface::class,
            ProviderRepositoryInterface::class,
            GeneratedContentRepositoryInterface::class,
            WorkflowRunRepositoryInterface::class,
            ActivityLogRepositoryInterface::class,
            MediaFileRepositoryInterface::class,
            AgentExecutionRepositoryInterface::class,
            PromptExecutionRepositoryInterface::class,
            ActivityLoggerInterface::class,
            StorageInterface::class,
            ExecutionTrackerInterface::class,
            ProjectServiceInterface::class,
            AssetServiceInterface::class,
            ContentServiceInterface::class,
            GenerationRequestServiceInterface::class,
            ProviderRegistryInterface::class,
            ProviderRegistryServiceInterface::class,
            WorkflowServiceInterface::class,
            AgentExecutionServiceInterface::class,
            PromptServiceInterface::class,
            HistoryServiceInterface::class,
            HealthServiceInterface::class,
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
