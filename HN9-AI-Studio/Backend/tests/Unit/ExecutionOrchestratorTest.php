<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\AI\Contracts\ProviderDispatcherInterface;
use App\AI\Execution\DispatchOptions;
use App\AI\Execution\DispatchResult;
use App\AI\Requests\TextRequest;
use App\AI\Responses\TextResponse;
use App\AI\Routing\ProviderCandidate;
use App\AI\Routing\RoutingContext;
use App\AI\Routing\RoutingPlan;
use App\AI\Support\Capability;
use App\AI\Support\CostStrategy;
use App\AI\Support\Modality;
use App\Contracts\Services\AgentExecutionServiceInterface;
use App\Contracts\Services\AssetServiceInterface;
use App\Contracts\Services\ContentServiceInterface;
use App\Contracts\Services\GenerationRequestServiceInterface;
use App\Contracts\Services\PromptRuntime\PromptContextBuilderInterface;
use App\Contracts\Services\PromptRuntime\PromptRendererInterface;
use App\Contracts\Services\PromptRuntime\PromptTemplateResolverInterface;
use App\Contracts\Services\PromptServiceInterface;
use App\Contracts\Services\WorkflowServiceInterface;
use App\DTOs\Generation\GenerationRequestData;
use App\DTOs\Prompt\PromptExecutionData;
use App\Models\AgentExecution;
use App\Models\GeneratedAsset;
use App\Models\GeneratedContent;
use App\Models\Project;
use App\Models\ProjectInput;
use App\Models\PromptExecution;
use App\Models\WorkflowRun;
use App\Services\ExecutionOrchestrator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class ExecutionOrchestratorTest extends TestCase
{
    use RefreshDatabase;

    public function test_execution_orchestrator_coordinates_the_generation_pipeline(): void
    {
        $project = Project::factory()->create([
            'status' => 'draft',
            'name' => 'Launch Campaign',
            'type' => 'marketing',
        ]);

        $request = new GenerationRequestData(
            project_id: $project->getKey(),
            deliverable_type: 'blog',
            user_id: 1,
            platform: 'web',
            language: 'en',
            topic: 'AI automation',
            goal: 'Create a landing page idea',
            payload: ['audience' => 'founders'],
        );

        $input = ProjectInput::factory()->make([
            'project_id' => $project->getKey(),
            'deliverable_type' => 'blog',
            'language' => 'en',
            'topic' => 'AI automation',
            'payload' => ['audience' => 'founders'],
        ]);
        $input->id = 11;

        $workflow = WorkflowRun::factory()->make([
            'project_id' => $project->getKey(),
            'workflow_key' => 'generation',
        ]);
        $workflow->id = 22;

        $agentExecution = AgentExecution::factory()->make([
            'workflow_run_id' => 1,
            'agent_key' => 'generation',
            'status' => 'pending',
        ]);
        $agentExecution->id = 33;

        $generation = Mockery::mock(GenerationRequestServiceInterface::class);
        $renderer = Mockery::mock(PromptRendererInterface::class);
        $templateResolver = Mockery::mock(PromptTemplateResolverInterface::class);
        $contextBuilder = Mockery::mock(PromptContextBuilderInterface::class);
        $prompts = Mockery::mock(PromptServiceInterface::class);
        $dispatcher = Mockery::mock(ProviderDispatcherInterface::class);
        $content = Mockery::mock(ContentServiceInterface::class);
        $assets = Mockery::mock(AssetServiceInterface::class);
        $workflowService = Mockery::mock(WorkflowServiceInterface::class);
        $agentExecutions = Mockery::mock(AgentExecutionServiceInterface::class);

        $context = [
            'project_name' => 'Launch Campaign',
            'company_name' => 'HN9',
            'language' => 'en',
            'topic' => 'AI automation',
        ];

        $renderedPrompt = 'Write a blog post about AI automation for founders.';

        $plan = new RoutingPlan(
            [new ProviderCandidate(key: 'openai', priority: 50, score: 0.9)],
            new RoutingContext(
                capability: Capability::Text,
                modality: Modality::Text,
                strategy: 'priority',
                costStrategy: CostStrategy::Balanced,
                model: 'gpt-4o-mini',
            ),
        );

        $dispatchResult = new DispatchResult(
            providerKey: 'openai',
            response: new TextResponse(text: 'Generated copy'),
            modality: Modality::Text,
            durationMs: 120,
            retries: 0,
            fallbacks: 0,
            estimatedCost: 0.01,
            plan: $plan,
        );

        $generation->shouldReceive('submit')->once()->withArgs(function ($projectArg, $dataArg, $causerArg = null) use ($project) {
            $this->assertSame($project->getKey(), $projectArg->getKey());
            $this->assertSame('blog', $dataArg->deliverable_type);

            return true;
        })->andReturn($input);

        $workflowService->shouldReceive('create')->once()->withArgs(function ($dto, $causer = null) use ($project) {
            $this->assertSame($project->getKey(), $dto->project_id);
            $this->assertSame('generation', $dto->workflow_key);

            return true;
        })->andReturn($workflow);

        $agentExecutions->shouldReceive('create')->once()->withArgs(function ($dto) use ($workflow) {
            $this->assertSame($workflow->getKey(), $dto->workflow_run_id);
            $this->assertSame('generation', $dto->agent_key);

            return true;
        })->andReturn($agentExecution);

        $templateResolver->shouldReceive('resolve')->once()->with('blog')->andReturn('Write a {{deliverable}} about {{topic}} for {{audience}}.');
        $contextBuilder->shouldReceive('build')->once()->with($project, Mockery::type('array'))->andReturn($context);
        $renderer->shouldReceive('render')->once()->with('Write a {{deliverable}} about {{topic}} for {{audience}}.', Mockery::type('array'))->andReturn($renderedPrompt);

        $prompts->shouldReceive('record')->once()->withArgs(function (PromptExecutionData $data) use ($agentExecution) {
            $this->assertSame($agentExecution->getKey(), $data->agent_execution_id);
            $this->assertSame('blog', $data->template_key);

            return true;
        })->andReturn(new PromptExecution([
            'agent_execution_id' => $agentExecution->getKey(),
            'template_key' => 'blog',
            'status' => 'pending',
            'rendered_prompt' => 'Write a blog post about AI automation for founders.',
        ]));

        $dispatcher->shouldReceive('dispatch')->once()->withArgs(function ($requestArg, $optionsArg = null) use ($renderedPrompt) {
            $this->assertInstanceOf(TextRequest::class, $requestArg);
            $this->assertSame($renderedPrompt, $requestArg->prompt);
            $this->assertInstanceOf(DispatchOptions::class, $optionsArg ?? DispatchOptions::make());

            return true;
        })->andReturn($dispatchResult);

        $contentModel = new GeneratedContent([
            'project_id' => 1,
            'type' => 'blog',
            'body' => 'Generated copy',
            'status' => 'draft',
            'version' => 1,
        ]);
        $contentModel->id = 44;

        $content->shouldReceive('create')->once()->withArgs(function ($dto, $causer = null) {
            $this->assertSame('blog', $dto->type);
            $this->assertSame('Generated copy', $dto->body);

            return true;
        })->andReturn($contentModel);

        $assetModel = new GeneratedAsset([
            'project_id' => 1,
            'generated_content_id' => $contentModel->getKey(),
            'type' => 'blog',
            'provider' => 'openai',
            'status' => 'completed',
            'prompt' => 'Write a blog post about AI automation for founders.',
        ]);
        $assetModel->id = 55;

        $assets->shouldReceive('create')->once()->withArgs(function ($dto, $causer = null) {
            $this->assertSame('blog', $dto->type);
            $this->assertSame('openai', $dto->provider);
            $this->assertSame('Write a blog post about AI automation for founders.', $dto->prompt);

            return true;
        })->andReturn($assetModel);

        $orchestrator = new ExecutionOrchestrator(
            generation: $generation,
            prompts: $prompts,
            templateResolver: $templateResolver,
            contextBuilder: $contextBuilder,
            promptRenderer: $renderer,
            dispatcher: $dispatcher,
            content: $content,
            assets: $assets,
            workflows: $workflowService,
            agentExecutions: $agentExecutions,
        );

        $result = $orchestrator->execute($project, $request, ['template_key' => 'blog', 'user' => null]);

        $this->assertArrayHasKey('project_input', $result);
        $this->assertArrayHasKey('prompt_execution', $result);
        $this->assertArrayHasKey('content', $result);
        $this->assertArrayHasKey('asset', $result);
        $this->assertSame('Generated copy', $result['content']->body);
        $this->assertSame('openai', $result['asset']->provider);

        // Routing stays the dispatcher's job; the orchestrator never plans a
        // second time, but the plan the dispatcher used is still reported.
        $this->assertSame('openai', $result['dispatch']['provider']);
        $this->assertSame(['openai'], $result['dispatch']['plan']);
    }
}
