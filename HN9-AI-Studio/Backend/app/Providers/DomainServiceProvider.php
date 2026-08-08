<?php

declare(strict_types=1);

namespace App\Providers;

use App\Contracts\Execution\ExecutionTrackerInterface;
use App\Contracts\Logging\ActivityLoggerInterface;
use App\Contracts\Providers\ProviderRegistryInterface;
use App\Contracts\Services\AgentExecutionServiceInterface;
use App\Contracts\Services\AssetServiceInterface;
use App\Contracts\Services\ContentRegenerationServiceInterface;
use App\Contracts\Services\ContentServiceInterface;
use App\Contracts\Services\ExecutionOrchestratorInterface;
use App\Contracts\Services\GenerationRequestServiceInterface;
use App\Contracts\Services\HealthServiceInterface;
use App\Contracts\Services\HistoryServiceInterface;
use App\Contracts\Services\ProjectServiceInterface;
use App\Contracts\Services\PromptRuntime\BrandContextServiceInterface;
use App\Contracts\Services\PromptRuntime\PromptContextBuilderInterface;
use App\Contracts\Services\PromptRuntime\PromptRendererInterface;
use App\Contracts\Services\PromptRuntime\PromptTemplateResolverInterface;
use App\Contracts\Services\PromptRuntime\PromptVariableResolverInterface;
use App\Contracts\Services\PromptServiceInterface;
use App\Contracts\Services\ProviderRegistryServiceInterface;
use App\Contracts\Services\UserServiceInterface;
use App\Contracts\Services\WorkflowServiceInterface;
use App\Contracts\Storage\StorageInterface;
use App\Repositories\ActivityLogRepository;
use App\Repositories\AgentExecutionRepository;
use App\Repositories\AssetRepository;
use App\Repositories\Contracts\ActivityLogRepositoryInterface;
use App\Repositories\Contracts\AgentExecutionRepositoryInterface;
use App\Repositories\Contracts\AssetRepositoryInterface;
use App\Repositories\Contracts\GeneratedContentRepositoryInterface;
use App\Repositories\Contracts\MediaFileRepositoryInterface;
use App\Repositories\Contracts\ProjectInputRepositoryInterface;
use App\Repositories\Contracts\ProjectRepositoryInterface;
use App\Repositories\Contracts\PromptExecutionRepositoryInterface;
use App\Repositories\Contracts\ProviderRepositoryInterface;
use App\Repositories\Contracts\ProviderSettingRepositoryInterface;
use App\Repositories\Contracts\UserRepositoryInterface;
use App\Repositories\Contracts\WorkflowRunRepositoryInterface;
use App\Repositories\GeneratedContentRepository;
use App\Repositories\MediaFileRepository;
use App\Repositories\ProjectInputRepository;
use App\Repositories\ProjectRepository;
use App\Repositories\PromptExecutionRepository;
use App\Repositories\ProviderRepository;
use App\Repositories\ProviderSettingRepository;
use App\Repositories\UserRepository;
use App\Repositories\WorkflowRunRepository;
use App\Services\AgentExecutionService;
use App\Services\AssetService;
use App\Services\ContentRegenerationService;
use App\Services\ContentService;
use App\Services\Execution\ExecutionTracker;
use App\Services\ExecutionOrchestrator;
use App\Services\GenerationRequestService;
use App\Services\HealthService;
use App\Services\HistoryService;
use App\Services\Logging\ActivityLogger;
use App\Services\ProjectService;
use App\Services\PromptRuntime\BrandContextService;
use App\Services\PromptRuntime\PromptContextBuilder;
use App\Services\PromptRuntime\PromptRenderer;
use App\Services\PromptRuntime\PromptTemplateResolver;
use App\Services\PromptRuntime\PromptVariableResolver;
use App\Services\PromptService;
use App\Services\ProviderRegistryService;
use App\Services\Storage\FilesystemStorage;
use App\Services\UserService;
use App\Services\WorkflowService;
use Illuminate\Support\ServiceProvider;

/**
 * Wires the HN9 domain layer into the container: repository, service, provider,
 * execution, storage and logging contracts are bound to their implementations
 * here. Every dependency in the domain is resolved through an interface, so any
 * implementation can be swapped without touching consumers.
 */
class DomainServiceProvider extends ServiceProvider
{
    /**
     * Repository contract => implementation.
     *
     * @var array<class-string, class-string>
     */
    private const REPOSITORIES = [
        ProjectRepositoryInterface::class => ProjectRepository::class,
        ProjectInputRepositoryInterface::class => ProjectInputRepository::class,
        AssetRepositoryInterface::class => AssetRepository::class,
        ProviderRepositoryInterface::class => ProviderRepository::class,
        ProviderSettingRepositoryInterface::class => ProviderSettingRepository::class,
        GeneratedContentRepositoryInterface::class => GeneratedContentRepository::class,
        WorkflowRunRepositoryInterface::class => WorkflowRunRepository::class,
        ActivityLogRepositoryInterface::class => ActivityLogRepository::class,
        MediaFileRepositoryInterface::class => MediaFileRepository::class,
        AgentExecutionRepositoryInterface::class => AgentExecutionRepository::class,
        PromptExecutionRepositoryInterface::class => PromptExecutionRepository::class,
    ];

    /**
     * Service/infrastructure contract => implementation.
     *
     * @var array<class-string, class-string>
     */
    private const SERVICES = [
        // Infrastructure
        ActivityLoggerInterface::class => ActivityLogger::class,
        StorageInterface::class => FilesystemStorage::class,
        ExecutionTrackerInterface::class => ExecutionTracker::class,

        // Domain services
        ProjectServiceInterface::class => ProjectService::class,
        AssetServiceInterface::class => AssetService::class,
        ContentServiceInterface::class => ContentService::class,
        ContentRegenerationServiceInterface::class => ContentRegenerationService::class,
        ExecutionOrchestratorInterface::class => ExecutionOrchestrator::class,
        GenerationRequestServiceInterface::class => GenerationRequestService::class,
        ProviderRegistryInterface::class => ProviderRegistryService::class,
        ProviderRegistryServiceInterface::class => ProviderRegistryService::class,
        WorkflowServiceInterface::class => WorkflowService::class,
        AgentExecutionServiceInterface::class => AgentExecutionService::class,
        PromptServiceInterface::class => PromptService::class,
        BrandContextServiceInterface::class => BrandContextService::class,
        PromptTemplateResolverInterface::class => PromptTemplateResolver::class,
        PromptVariableResolverInterface::class => PromptVariableResolver::class,
        PromptRendererInterface::class => PromptRenderer::class,
        PromptContextBuilderInterface::class => PromptContextBuilder::class,
        HistoryServiceInterface::class => HistoryService::class,
        HealthServiceInterface::class => HealthService::class,
    ];

    public function register(): void
    {
        foreach ([...self::REPOSITORIES, ...self::SERVICES] as $abstract => $concrete) {
            $this->app->bind($abstract, $concrete);
        }

        // Bind user repository & service
        $this->app->bind(UserRepositoryInterface::class, UserRepository::class);
        $this->app->bind(UserServiceInterface::class, UserService::class);

        // The provider registry is a single stateless read model shared by both
        // its read contract and its management contract (autowired).
        $this->app->singleton(ProviderRegistryService::class);
    }

    public function boot(): void
    {
        //
    }
}
