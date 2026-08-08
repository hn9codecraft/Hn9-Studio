<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\Services\ContentRegenerationServiceInterface;
use App\Contracts\Services\ExecutionOrchestratorInterface;
use App\Contracts\Services\PromptServiceInterface;
use App\DTOs\Generation\GenerationRequestData;
use App\Exceptions\GenerationException;
use App\Models\GeneratedContent;
use App\Models\User;
use Illuminate\Support\Arr;

/**
 * Re-runs the generation pipeline for existing content.
 *
 * Every step of the actual work belongs to services that already exist: the
 * request DTO, the prompt-execution record that remembers the original
 * variables, and the orchestrator that owns the pipeline. This service only
 * reconstructs the request and delegates.
 */
final readonly class ContentRegenerationService implements ContentRegenerationServiceInterface
{
    public function __construct(
        private ExecutionOrchestratorInterface $orchestrator,
        private PromptServiceInterface $prompts,
    ) {}

    public function regenerate(GeneratedContent $content, array $overrides = [], ?User $causer = null): array
    {
        $project = $content->project;

        if ($project === null) {
            throw new GenerationException(
                message: 'The content to regenerate no longer has an owning project.',
                errorCode: 'content_project_missing',
                statusCode: 409,
                context: ['content' => $content->uuid],
            );
        }

        $structured = (array) ($content->structured ?? []);

        $data = new GenerationRequestData(
            project_id: $project->getKey(),
            deliverable_type: $content->type,
            user_id: $causer?->getKey() ?? $project->user_id,
            platform: $content->channel,
            language: (string) ($content->language ?? 'en'),
            topic: $this->stringOrNull(Arr::get($overrides, 'topic')) ?? $content->title,
            goal: $this->stringOrNull(Arr::get($overrides, 'goal')),
            payload: (array) Arr::get($overrides, 'payload', []),
            source: 'regenerate',
        );

        // The variables the prompt was originally rendered with are the only
        // faithful basis for a re-run; an explicit override wins over them.
        $variables = [
            ...$this->originalVariables($content),
            ...(array) Arr::get($overrides, 'variables', []),
        ];

        $options = [
            'user' => $causer,
            'template_key' => $this->stringOrNull(Arr::get($overrides, 'template_key'))
                ?? $this->stringOrNull(Arr::get($structured, 'template_key'))
                ?? $content->type,
            'variables' => $variables,
            'channel' => $content->channel ?? 'default',
            'regenerated_from' => $content->uuid,
        ];

        $model = $this->stringOrNull(Arr::get($overrides, 'model'));

        if ($model !== null) {
            $options['model'] = $model;
        }

        return $this->orchestrator->execute($project, $data, $options);
    }

    /**
     * The variable set recorded for the prompt execution that produced this
     * content, or an empty set when the content predates prompt recording.
     *
     * @return array<string, mixed>
     */
    private function originalVariables(GeneratedContent $content): array
    {
        $agentExecution = $content->agentExecution;

        if ($agentExecution === null) {
            return [];
        }

        $executions = $this->prompts->forAgentExecution($agentExecution);

        if ($executions->isEmpty()) {
            return [];
        }

        // Prompt executions come back in insertion order, so the last entry is
        // the most recent render for that execution.
        return (array) ($executions->last()->variables ?? []);
    }

    private function stringOrNull(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
