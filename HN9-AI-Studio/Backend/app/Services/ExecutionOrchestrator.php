<?php

declare(strict_types=1);

namespace App\Services;

use App\AI\Contracts\ProviderDispatcherInterface;
use App\AI\Execution\DispatchOptions;
use App\AI\Requests\TextRequest;
use App\AI\Responses\TextResponse;
use App\Contracts\Services\AgentExecutionServiceInterface;
use App\Contracts\Services\AssetServiceInterface;
use App\Contracts\Services\ContentServiceInterface;
use App\Contracts\Services\ExecutionOrchestratorInterface;
use App\Contracts\Services\GenerationRequestServiceInterface;
use App\Contracts\Services\PromptRuntime\PromptContextBuilderInterface;
use App\Contracts\Services\PromptRuntime\PromptRendererInterface;
use App\Contracts\Services\PromptRuntime\PromptTemplateResolverInterface;
use App\Contracts\Services\PromptServiceInterface;
use App\Contracts\Services\WorkflowServiceInterface;
use App\DTOs\Agent\AgentExecutionData;
use App\DTOs\Asset\AssetData;
use App\DTOs\Content\ContentData;
use App\DTOs\Generation\GenerationRequestData;
use App\DTOs\Prompt\PromptExecutionData;
use App\DTOs\Workflow\WorkflowRunData;
use App\Enums\ExecutionStatus;
use App\Enums\WorkflowStatus;
use App\Exceptions\GenerationException;
use App\Models\AgentExecution;
use App\Models\Project;
use App\Models\WorkflowRun;
use Illuminate\Support\Arr;

/**
 * Coordinates the existing generation services without redesigning or
 * duplicating provider or prompt logic. The orchestrator only connects the
 * already-completed runtime and persistence layers.
 *
 * Routing is deliberately *not* invoked here. ProviderDispatcher already builds
 * the RoutingContext from the request and plans through ProviderRouter on the
 * caller's behalf, and reports the resulting plan back on its DispatchResult.
 * Routing from the orchestrator as well would plan every generation twice off a
 * hand-built context that can diverge from the one the dispatcher actually uses.
 */
final readonly class ExecutionOrchestrator implements ExecutionOrchestratorInterface
{
    public function __construct(
        private GenerationRequestServiceInterface $generation,
        private PromptServiceInterface $prompts,
        private PromptTemplateResolverInterface $templateResolver,
        private PromptContextBuilderInterface $contextBuilder,
        private PromptRendererInterface $promptRenderer,
        private ProviderDispatcherInterface $dispatcher,
        private ContentServiceInterface $content,
        private AssetServiceInterface $assets,
        private ?WorkflowServiceInterface $workflows = null,
        private ?AgentExecutionServiceInterface $agentExecutions = null,
    ) {}

    public function execute(Project $project, GenerationRequestData $data, array $options = []): array
    {
        $user = $options['user'] ?? null;
        $templateKey = (string) ($options['template_key'] ?? $data->deliverable_type);

        $input = $this->generation->submit($project, $data, $user);
        $workflowRun = $this->createWorkflowRun($project, $data, $user);
        $agentExecution = $this->createAgentExecution($workflowRun, $data, $user);

        $promptExecutionId = (int) ($agentExecution?->getKey() ?? $workflowRun?->getKey() ?? $input->getKey() ?? 0);
        if ($promptExecutionId <= 0) {
            throw new \RuntimeException('Execution orchestrator requires a persisted agent execution or project input before prompt recording.');
        }

        $promptContext = $this->contextBuilder->build($project, [
            'language' => $data->language,
            'deliverable_type' => $data->deliverable_type,
            'topic' => $data->topic,
            'goal' => $data->goal,
            'payload' => $data->payload,
            ...$options,
        ]);

        // deliverable_type is a free-form string at the request boundary, so a
        // value outside the prompt catalog is client error, not a server fault.
        try {
            $template = $this->templateResolver->resolve($templateKey);
        } catch (\InvalidArgumentException) {
            throw GenerationException::unsupportedDeliverable($templateKey);
        }

        $variables = $this->normalizeVariables($data, $promptContext, $options);

        // The resolver rejects a template whose placeholders the request cannot
        // fill. That is a client-supplied gap, so it is a 422 naming the missing
        // variable rather than an unhandled 500.
        try {
            $renderedPrompt = $this->promptRenderer->render($template, $variables);
        } catch (\InvalidArgumentException $exception) {
            throw new GenerationException(
                message: $exception->getMessage(),
                errorCode: 'generation_missing_prompt_variable',
                statusCode: 422,
                context: ['template_key' => $templateKey],
                previous: $exception,
            );
        }

        $promptExecution = $this->prompts->record(new PromptExecutionData(
            agent_execution_id: $promptExecutionId,
            template_key: $templateKey,
            ai_provider_id: Arr::get($options, 'ai_provider_id'),
            template_version: Arr::get($options, 'template_version'),
            model: Arr::get($options, 'model'),
            status: ExecutionStatus::Queued->value,
            variables: $variables,
        ));

        $request = new TextRequest(prompt: $renderedPrompt, model: Arr::get($options, 'model'));
        $dispatchResult = $this->dispatcher->dispatch($request, DispatchOptions::make());

        $text = $dispatchResult->response instanceof TextResponse
            ? $dispatchResult->response->text
            : (string) Arr::get($dispatchResult->response->toArray(), 'text', '');

        $content = $this->content->create(new ContentData(
            project_id: $project->getKey(),
            type: $data->deliverable_type,
            workflow_run_id: $workflowRun?->getKey(),
            agent_execution_id: $agentExecution?->getKey(),
            channel: (string) ($options['channel'] ?? 'default'),
            language: $data->language,
            title: $data->topic,
            body: $text,
            structured: [
                'template_key' => $templateKey,
                'provider' => $dispatchResult->providerKey,
                'prompt' => $renderedPrompt,
            ],
            status: ExecutionStatus::Completed->value,
            metadata: [
                'provider' => $dispatchResult->providerKey,
                'dispatch' => $dispatchResult->toArray(),
            ],
        ));

        $asset = $this->assets->create(new AssetData(
            project_id: $project->getKey(),
            type: $data->deliverable_type,
            generated_content_id: $content->getKey(),
            workflow_run_id: $workflowRun?->getKey(),
            agent_execution_id: $agentExecution?->getKey(),
            provider: $dispatchResult->providerKey,
            status: ExecutionStatus::Completed->value,
            prompt: $renderedPrompt,
            metadata: [
                'source' => 'execution_orchestrator',
                'provider_result' => $dispatchResult->toArray(),
            ],
        ));

        return [
            'project_input' => $input,
            'workflow_run' => $workflowRun,
            'agent_execution' => $agentExecution,
            'prompt_execution' => $promptExecution,
            'dispatch' => $dispatchResult->toArray(),
            'content' => $content,
            'asset' => $asset,
        ];
    }

    /**
     * Assemble the template variables from brand context, the request and the
     * caller's overrides, in ascending order of precedence.
     *
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>  $options
     * @return array<string, string>
     */
    private function normalizeVariables(GenerationRequestData $data, array $context, array $options = []): array
    {
        $variables = [
            'company_name' => Arr::get($context, 'company_name', 'HN9'),
            'project_name' => Arr::get($context, 'project_name', ''),
            'project_type' => Arr::get($context, 'project_type', $data->deliverable_type),
            'deliverable_type' => $data->deliverable_type,
            'platform' => $data->platform ?? 'web',
            'language' => $data->language,
            'topic' => $data->topic ?? '',
            'goal' => $data->goal ?? '',
            'audience' => Arr::get($context, 'audience', []),
            'tone' => Arr::get($context, 'tone', ''),
        ];

        foreach ($data->payload as $key => $value) {
            $variables[$key] = $value;
        }

        // An explicit override wins over both brand context and payload, and is
        // the seam a caller uses to fill template-specific variables.
        foreach ((array) Arr::get($options, 'variables', []) as $key => $value) {
            $variables[$key] = $value;
        }

        foreach ($options as $key => $value) {
            if (! array_key_exists($key, $variables) && is_scalar($value)) {
                $variables[$key] = $value;
            }
        }

        // The renderer substitutes values straight into prompt text, so every
        // variable has to reach it as a string. The brand context supplies lists
        // (audience segments, brand colors) that would otherwise be cast to the
        // literal "Array".
        return array_map($this->stringifyVariable(...), $variables);
    }

    /**
     * Flatten one variable into prompt-ready text. Lists become comma-separated
     * so they read naturally inside a sentence.
     */
    private function stringifyVariable(mixed $value): string
    {
        if (is_array($value)) {
            $parts = array_map($this->stringifyVariable(...), array_values($value));

            return implode(', ', array_filter($parts, static fn (string $part): bool => $part !== ''));
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if ($value === null) {
            return '';
        }

        return is_scalar($value) ? (string) $value : (string) json_encode($value);
    }

    private function createWorkflowRun(Project $project, GenerationRequestData $data, mixed $causer = null): ?WorkflowRun
    {
        if ($this->workflows === null) {
            return null;
        }

        return $this->workflows->create(new WorkflowRunData(
            project_id: $project->getKey(),
            workflow_key: 'generation',
            user_id: $causer?->getKey() ?? $data->user_id,
            status: WorkflowStatus::Pending->value,
            current_stage: 'prompt_dispatch',
            total_steps: 4,
            context: [
                'deliverable_type' => $data->deliverable_type,
                'language' => $data->language,
                'template_key' => $data->deliverable_type,
            ],
        ), $causer);
    }

    private function createAgentExecution(?WorkflowRun $workflowRun, GenerationRequestData $data, mixed $causer = null): ?AgentExecution
    {
        if ($this->agentExecutions === null || $workflowRun === null) {
            return null;
        }

        return $this->agentExecutions->create(new AgentExecutionData(
            workflow_run_id: $workflowRun->getKey(),
            agent_key: 'generation',
            ai_provider_id: null,
            agent_version: '1.0.0',
            status: ExecutionStatus::Pending->value,
            attempt: 1,
            input: [
                'deliverable_type' => $data->deliverable_type,
                'language' => $data->language,
                'topic' => $data->topic,
                'goal' => $data->goal,
            ],
        ));
    }
}
