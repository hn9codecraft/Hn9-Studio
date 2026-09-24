<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\Logging\ActivityLoggerInterface;
use App\Contracts\Services\ExecutionOrchestratorInterface;
use App\Contracts\Services\ScriptGenerationServiceInterface;
use App\Contracts\Services\ScriptServiceInterface;
use App\DTOs\Generation\GenerationRequestData;
use App\DTOs\Script\CreateScriptData;
use App\Enums\ScriptSource;
use App\Enums\ScriptStatus;
use App\Exceptions\GenerationException;
use App\Models\GeneratedContent;
use App\Models\Project;
use App\Models\PromptExecution;
use App\Models\Script;
use App\Models\User;
use Illuminate\Support\Arr;

/**
 * Studio-facing generation: reuses ExecutionOrchestrator (M10.1) and writes a
 * new Script row linked to generated_contents. Existing scripts are never
 * overwritten.
 */
final readonly class ScriptGenerationService implements ScriptGenerationServiceInterface
{
    /**
     * Placeholders the script.md prompt catalog expects. Empty strings are
     * valid fills — they are not invented AI output.
     *
     * @var list<string>
     */
    private const TEMPLATE_KEYS = [
        'duration',
        'audience',
        'tone',
        'cta',
        'service',
        'video_style',
        'voice_style',
        'brand_rules',
        'key_points',
        'additional_instructions',
    ];

    public function __construct(
        private ExecutionOrchestratorInterface $orchestrator,
        private ScriptServiceInterface $scripts,
        private ActivityLoggerInterface $activity,
    ) {}

    public function generate(Project $project, array $input, ?User $causer = null, ?Script $parent = null): array
    {
        $merged = $this->mergeParentBrief($input, $parent);
        $payload = $this->studioPayload($merged);

        if ($this->nullableString($merged['topic'] ?? null) === null) {
            throw new GenerationException(
                message: 'A topic or brief is required to generate a script.',
                errorCode: 'script_generation_topic_required',
                statusCode: 422,
            );
        }

        $dto = new GenerationRequestData(
            project_id: $project->getKey(),
            deliverable_type: 'script',
            user_id: $causer?->getKey(),
            platform: $this->nullableString($merged['platform'] ?? null) ?? 'instagram',
            language: $this->nullableString($merged['language'] ?? null) ?? 'en',
            topic: $this->nullableString($merged['topic'] ?? null),
            goal: $this->nullableString($merged['goal'] ?? null),
            payload: $payload,
            source: 'script_studio',
            type: 'brief',
        );

        return $this->persistStudioScript($project, $dto, $merged, $payload, $causer, $parent);
    }

    /**
     * @param  array<string, mixed>  $merged
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function persistStudioScript(
        Project $project,
        GenerationRequestData $dto,
        array $merged,
        array $payload,
        ?User $causer,
        ?Script $parent,
    ): array {
        $result = $this->orchestrator->execute($project, $dto, [
            'user' => $causer,
            'template_key' => 'script',
        ]);

        $content = $result['content'] ?? null;

        if (! $content instanceof GeneratedContent || ! is_string($content->body) || $content->body === '') {
            throw new \RuntimeException('Generation completed without a usable script body.');
        }

        $prompt = $result['prompt_execution'] ?? null;
        $dispatch = is_array($result['dispatch'] ?? null) ? $result['dispatch'] : [];

        $script = $this->scripts->create(new CreateScriptData(
            project_id: $project->getKey(),
            title: $this->titleFrom($merged, $content),
            body: $content->body,
            status: ScriptStatus::Draft->value,
            source: ScriptSource::Ai->value,
            generated_content_id: $content->getKey(),
            parent_script_id: $parent?->getKey(),
            generation: [
                'deliverable_type' => 'script',
                'platform' => $dto->platform,
                'language' => $dto->language,
                'topic' => $dto->topic,
                'goal' => $dto->goal,
                'payload' => $payload,
                'provider' => $dispatch['provider'] ?? null,
                'generated_content_id' => $content->uuid,
                'prompt_execution_id' => $prompt instanceof PromptExecution ? $prompt->uuid : null,
                'parent_script_id' => $parent?->uuid,
            ],
        ), $causer);

        $this->activity->log(
            $parent === null ? 'script.generated' : 'script.regenerated',
            $script,
            $causer,
            $parent === null ? 'AI script generated' : 'AI script variation generated',
            [
                'parent_script_id' => $parent?->uuid,
                'generated_content_id' => $content->uuid,
                'provider' => $dispatch['provider'] ?? null,
            ],
        );

        return [
            'script' => $script->load(['project', 'generatedContent', 'parentScript']),
            'dispatch' => $dispatch,
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function mergeParentBrief(array $input, ?Script $parent): array
    {
        $brief = is_array($parent?->generation) ? $parent->generation : [];
        $parentPayload = is_array($brief['payload'] ?? null) ? $brief['payload'] : [];

        $base = [
            'topic' => $brief['topic'] ?? null,
            'platform' => $brief['platform'] ?? null,
            'language' => $brief['language'] ?? null,
            'goal' => $brief['goal'] ?? null,
            ...$parentPayload,
        ];

        foreach ($input as $key => $value) {
            if ($value !== null && $value !== '') {
                $base[$key] = $value;
            }
        }

        return $base;
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function studioPayload(array $input): array
    {
        $payload = [];

        foreach (self::TEMPLATE_KEYS as $key) {
            $payload[$key] = $this->nullableString(Arr::get($input, $key)) ?? '';
        }

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function titleFrom(array $input, GeneratedContent $content): string
    {
        $explicit = $this->nullableString($input['title'] ?? null);

        if ($explicit !== null) {
            return $explicit;
        }

        $topic = $this->nullableString($input['topic'] ?? $content->title);

        return $topic !== null ? $topic : 'AI generated script';
    }

    private function nullableString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
