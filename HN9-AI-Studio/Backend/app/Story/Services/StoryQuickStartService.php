<?php

declare(strict_types=1);

namespace App\Story\Services;

use App\AI\Support\ProviderErrorSanitizer;
use App\Contracts\Logging\ActivityLoggerInterface;
use App\Models\Project;
use App\Models\User;
use App\Story\Contracts\StoryBibleServiceInterface;
use App\Story\Contracts\StoryCharacterServiceInterface;
use App\Story\Contracts\StoryPlannerServiceInterface;
use App\Story\Contracts\StoryStyleBibleServiceInterface;
use App\Story\Contracts\StoryWorkspaceServiceInterface;
use App\Story\Enums\StoryCharacterStatus;
use App\Story\Exceptions\StoryBudgetExceededException;
use App\Story\Exceptions\StoryPlannerException;
use App\Story\Exceptions\StoryQuickStartException;
use App\Story\Models\StoryCharacter;
use App\Story\Models\StoryPlan;
use App\Story\Models\StoryPlanVersion;
use App\Story\Support\StoryPlanDurationCalculator;
use App\Story\Support\StoryPlannerPromptBuilder;
use App\Story\Support\StoryTemplateCatalog;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Fills a story draft from a template and generates one plan version.
 * Plan generation goes through StoryPlannerService, which owns the budget guard.
 * Planner failure is returned as HTTP 200 with plan_generation "failed" so the
 * saved draft is still visible. A direct plan generate call still returns 502.
 */
final readonly class StoryQuickStartService
{
    public function __construct(
        private StoryTemplateCatalog $templates,
        private StoryWorkspaceServiceInterface $workspaces,
        private StoryBibleServiceInterface $bibles,
        private StoryStyleBibleServiceInterface $styles,
        private StoryCharacterServiceInterface $characters,
        private StoryPlannerServiceInterface $planner,
        private StoryPlannerPromptBuilder $prompts,
        private StoryPlanDurationCalculator $durations,
        private StoryCostEstimator $costs,
        private StorySpendService $spend,
        private StoryReadinessService $readiness,
        private ActivityLoggerInterface $activity,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function estimate(Project $project, array $input): array
    {
        $template = $this->templates->get((string) $input['template']);
        $prompt = $this->prompt($template, $input);

        return [
            'plan_estimate' => $this->costs->estimate($project, 'plan_generate', ['text' => $prompt])->toArray(),
            'full_video_preview' => $this->costs->productionPreview($project, (int) $input['duration_seconds']),
            'budget' => $this->spend->summary($project),
            'text_connected' => (bool) ($this->readiness->connections()['story_planning'] ?? false),
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function run(Project $project, User $actor, array $input): array
    {
        $workspace = $this->workspaces->workspaceForProject($project);
        $cached = $this->remembered($workspace->id, $input);
        if ($cached !== null) {
            return $cached;
        }

        $lock = Cache::lock('story-quick-start:'.$workspace->id, 120);
        if (! $lock->get()) {
            throw StoryQuickStartException::inProgress();
        }

        try {
            $again = $this->remembered($workspace->id, $input);
            if ($again !== null) {
                return $again;
            }

            $templateKey = (string) $input['template'];
            $template = $this->templates->get($templateKey);

            $draft = DB::transaction(fn (): array => $this->writeDrafts($project, $templateKey, $template, $input));
            $result = $this->generatePlan($project, $actor, $templateKey, $template, $input, $draft);
            $this->remember($workspace->id, $input, $result);

            return $result;
        } finally {
            $lock->release();
        }
    }

    /**
     * @param  array<string, mixed>  $template
     * @param  array<string, mixed>  $input
     */
    private function prompt(array $template, array $input): string
    {
        $duration = (int) $input['duration_seconds'];
        $plan = $this->durations->calculate($duration);
        $built = $this->prompts->build($this->previewContext($template, $input), $plan);

        return (string) ($built['prompt'] ?? '');
    }

    /**
     * @param  array<string, mixed>  $template
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function previewContext(array $template, array $input): array
    {
        $defaults = is_array($template['defaults'] ?? null) ? $template['defaults'] : [];
        $style = $this->styleAttributes($template, $input);
        $duration = (int) $input['duration_seconds'];
        $topic = trim((string) $input['topic']);
        $characters = [];
        foreach ($this->requestedCharacters($input, $template) as $character) {
            $characters[] = [
                'id' => null,
                'name' => $character['name'],
                'short_description' => $character['short_description'],
                'approved_reference' => null,
            ];
        }

        return [
            'story_details' => [
                'id' => null,
                'configured' => true,
                'concept' => $topic,
                'genre' => $defaults['genre'] ?? null,
                'audience' => $this->nullableString($input['audience'] ?? null) ?? ($defaults['audience'] ?? null),
                'language' => $this->language($input, $defaults),
                'tone' => $this->nullableString($input['tone'] ?? null) ?? ($defaults['tone'] ?? null),
                'world' => null,
                'location' => null,
                'time_period' => null,
                'narrative_style' => $defaults['narrative_style'] ?? null,
                'video_style' => $this->nullableString($input['style'] ?? null) ?? ($defaults['video_style'] ?? null),
                'aspect_ratio' => $this->nullableString($input['aspect_ratio'] ?? null) ?? ($defaults['aspect_ratio'] ?? null),
                'default_duration' => $duration,
            ],
            'characters' => $characters,
            'visual_style' => [
                'id' => null,
                'configured' => true,
                'visual_style' => $style['visual_style'] ?? null,
                'animation_style' => null,
                'lighting' => $style['lighting'] ?? null,
                'camera_style' => $style['camera_style'] ?? null,
                'color_direction' => $style['color_direction'] ?? null,
                'environment_style' => null,
                'mood' => $style['mood'] ?? null,
                'rendering_style' => null,
                'visual_quality' => null,
                'art_direction_notes' => $style['art_direction_notes'] ?? null,
                'aspect_ratio' => $style['aspect_ratio'] ?? null,
                'approved_reference' => null,
            ],
            'current_request' => [
                'plan_id' => null,
                'title' => $this->title($template, $topic),
                'idea' => $this->templates->composeIdea($template, $input),
                'requested_duration_seconds' => $duration,
            ],
            'previous_plan' => null,
        ];
    }

    /**
     * @param  array<string, mixed>  $template
     * @param  array<string, mixed>  $input
     * @return array{bible: mixed, style: mixed, created: list<array{id: string, name: string}>, skipped: list<string>, plan: StoryPlan}
     */
    private function writeDrafts(Project $project, string $templateKey, array $template, array $input): array
    {
        $workspace = $this->workspaces->workspaceForProject($project);
        $overwrite = (bool) ($input['overwrite'] ?? false);
        $bible = $this->bibles->bibleForProject($project);
        $plans = StoryPlan::query()
            ->where('story_workspace_id', $workspace->id)
            ->where('status', '!=', 'archived')
            ->get(['uuid', 'title', 'status']);
        $concept = trim((string) $bible->concept);
        if (! $overwrite && ($concept !== '' || $plans->isNotEmpty())) {
            throw StoryQuickStartException::conflict([
                'has_concept' => $concept !== '',
                'plans' => $plans->map(static fn (StoryPlan $plan): array => [
                    'id' => $plan->uuid,
                    'title' => $plan->title,
                    'status' => $plan->status,
                ])->values()->all(),
            ]);
        }

        $defaults = is_array($template['defaults'] ?? null) ? $template['defaults'] : [];
        $duration = (int) $input['duration_seconds'];
        $topic = trim((string) $input['topic']);
        $bible = $this->bibles->updateForProject($project, [
            'concept' => $topic,
            'genre' => $defaults['genre'] ?? null,
            'audience' => $this->nullableString($input['audience'] ?? null) ?? ($defaults['audience'] ?? null),
            'language' => $this->language($input, $defaults),
            'tone' => $this->nullableString($input['tone'] ?? null) ?? ($defaults['tone'] ?? null),
            'narrative_style' => $defaults['narrative_style'] ?? null,
            'video_style' => $this->nullableString($input['style'] ?? null) ?? ($defaults['video_style'] ?? null),
            'aspect_ratio' => $this->nullableString($input['aspect_ratio'] ?? null) ?? ($defaults['aspect_ratio'] ?? null),
            'default_duration' => $duration,
            'audio_defaults' => is_array($template['audio_defaults'] ?? null) ? $template['audio_defaults'] : [],
        ]);
        $style = $this->styles->updateForProject($project, $this->styleAttributes($template, $input));
        [$created, $skipped] = $this->createCharacters($project, $workspace->id, $this->requestedCharacters($input, $template));
        $plan = $this->planner->create($project, [
            'title' => $this->title($template, $topic),
            'idea' => $this->templates->composeIdea($template, $input),
            'requested_duration_seconds' => $duration,
        ]);

        return [
            'bible' => $bible,
            'style' => $style,
            'created' => $created,
            'skipped' => $skipped,
            'plan' => $plan,
        ];
    }

    /**
     * @param  array<string, mixed>  $template
     * @param  array<string, mixed>  $input
     * @param  array{bible: mixed, style: mixed, created: list<array{id: string, name: string}>, skipped: list<string>, plan: StoryPlan}  $draft
     * @return array<string, mixed>
     */
    private function generatePlan(Project $project, User $actor, string $templateKey, array $template, array $input, array $draft): array
    {
        /** @var StoryPlan $plan */
        $plan = $draft['plan'];
        $created = $draft['created'];
        $skipped = $draft['skipped'];
        $prompt = $this->prompt($template, $input);
        $estimate = $this->costs->estimate($project, 'plan_generate', ['text' => $prompt])->toArray();
        $connected = (bool) ($this->readiness->connections()['story_planning'] ?? false);
        $outcome = 'not_connected';
        $error = null;

        if (! $connected) {
            $this->log($actor, $plan, $templateKey, $input, $outcome);

            return $this->payload($templateKey, $template, $draft, $created, $skipped, $plan, $outcome, $estimate, $error);
        }

        try {
            $plan = $this->planner->generate($project, $plan->uuid);
            $plan->loadMissing('currentVersion');
            [$extra, $extraSkipped] = $this->createCharacters(
                $project,
                (int) $plan->story_workspace_id,
                $this->castFromPlan($plan),
                8,
            );
            $created = [...$created, ...$extra];
            $skipped = [...$skipped, ...$extraSkipped];
            $outcome = 'ready_for_review';
        } catch (StoryBudgetExceededException $exception) {
            $this->log($actor, $plan, $templateKey, $input, 'budget_exceeded');
            throw new StoryBudgetExceededException($exception->getMessage(), [
                ...$exception->context(),
                'drafts_saved' => true,
                'drafts' => [
                    'bible_id' => $draft['bible']->uuid ?? null,
                    'style_id' => $draft['style']->uuid ?? null,
                    'plan_id' => $plan->uuid,
                ],
            ]);
        } catch (StoryPlannerException $exception) {
            $plan = $plan->fresh() ?? $plan;
            $outcome = 'failed';
            $error = ProviderErrorSanitizer::message($exception->getMessage(), 'The plan could not be generated.');
        } catch (Throwable $exception) {
            $plan = $plan->fresh() ?? $plan;
            $outcome = 'failed';
            $error = ProviderErrorSanitizer::message($exception->getMessage(), 'The plan could not be generated.');
        }

        $this->log($actor, $plan, $templateKey, $input, $outcome);

        return $this->payload($templateKey, $template, $draft, $created, $skipped, $plan, $outcome, $estimate, $error);
    }

    /**
     * @param  list<array{name: string, short_description: ?string}>  $wanted
     * @return array{0: list<array{id: string, name: string}>, 1: list<string>}
     */
    private function createCharacters(Project $project, int $workspaceId, array $wanted, ?int $limit = null): array
    {
        $existing = StoryCharacter::query()->where('story_workspace_id', $workspaceId)->get();
        $known = [];
        foreach ($existing as $character) {
            $known[mb_strtolower($character->name)] = true;
        }
        $created = [];
        $skipped = [];
        foreach ($wanted as $character) {
            $name = $character['name'];
            $key = mb_strtolower($name);
            if (isset($known[$key])) {
                $skipped[] = $name;
                continue;
            }
            if ($limit !== null && count($created) >= $limit) {
                break;
            }
            $saved = $this->characters->create($project, [
                'name' => $name,
                'short_description' => $character['short_description'],
                'status' => StoryCharacterStatus::Draft->value,
            ]);
            $known[$key] = true;
            $created[] = ['id' => $saved->uuid, 'name' => $saved->name];
        }

        return [$created, $skipped];
    }

    /**
     * @return list<array{name: string, short_description: ?string}>
     */
    private function castFromPlan(StoryPlan $plan): array
    {
        $scenes = $plan->currentVersion?->plan['scenes'] ?? [];
        if (! is_array($scenes)) {
            return [];
        }
        $names = [];
        $seen = [];
        foreach ($scenes as $scene) {
            if (! is_array($scene)) {
                continue;
            }
            foreach ($scene['characters'] ?? [] as $name) {
                if (! is_string($name)) {
                    continue;
                }
                $name = trim($name);
                $key = mb_strtolower($name);
                if ($name === '' || isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $names[] = ['name' => $name, 'short_description' => null];
            }
        }

        return $names;
    }

    /**
     * @param  array<string, mixed>  $input
     * @param  array<string, mixed>  $template
     * @return list<array{name: string, short_description: ?string}>
     */
    private function requestedCharacters(array $input, array $template): array
    {
        $characters = [];
        foreach (is_array($input['characters'] ?? null) ? $input['characters'] : [] as $character) {
            if (! is_array($character)) {
                continue;
            }
            $name = trim((string) ($character['name'] ?? ''));
            if ($name === '') {
                continue;
            }
            $description = $character['short_description'] ?? null;
            $characters[] = [
                'name' => $name,
                'short_description' => is_string($description) && trim($description) !== '' ? trim($description) : null,
            ];
        }
        foreach (is_array($template['characters'] ?? null) ? $template['characters'] : [] as $character) {
            if (! is_array($character)) {
                continue;
            }
            $name = trim((string) ($character['name'] ?? ''));
            if ($name === '') {
                continue;
            }
            $characters[] = [
                'name' => $name,
                'short_description' => isset($character['short_description']) ? (string) $character['short_description'] : null,
            ];
        }

        return $characters;
    }

    /**
     * @param  array<string, mixed>  $template
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function styleAttributes(array $template, array $input): array
    {
        $defaults = is_array($template['defaults'] ?? null) ? $template['defaults'] : [];
        $style = is_array($template['style'] ?? null) ? $template['style'] : [];
        $override = $this->nullableString($input['style'] ?? null);
        $style['aspect_ratio'] = $this->nullableString($input['aspect_ratio'] ?? null) ?? ($defaults['aspect_ratio'] ?? null);
        if ($override !== null) {
            $style['visual_style'] = $override;
            $style['art_direction_notes'] = $override;
        }

        return $style;
    }

    /**
     * @param  array<string, mixed>  $template
     */
    private function title(array $template, string $topic): string
    {
        $title = (string) ($template['label'] ?? 'Story').': '.mb_substr($topic, 0, 80);

        return mb_substr($title, 0, 200);
    }

    /**
     * @param  array<string, mixed>  $input
     * @param  array<string, mixed>  $defaults
     */
    private function language(array $input, array $defaults): ?string
    {
        $language = array_key_exists('language', $input) && $input['language'] !== null
            ? (string) $input['language']
            : (string) ($defaults['language'] ?? '');

        return $language === '' ? null : $language;
    }

    private function nullableString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $value = trim($value);

        return $value === '' ? null : $value;
    }

    /**
     * @param  array<string, mixed>  $template
     * @param  array{bible: mixed, style: mixed}  $draft
     * @param  list<array{id: string, name: string}>  $created
     * @param  list<string>  $skipped
     * @param  array<string, mixed>  $estimate
     * @return array<string, mixed>
     */
    private function payload(
        string $templateKey,
        array $template,
        array $draft,
        array $created,
        array $skipped,
        StoryPlan $plan,
        string $outcome,
        array $estimate,
        ?string $error,
    ): array {
        $plan->loadMissing('currentVersion');
        $version = $plan->currentVersion;

        return [
            'template' => (string) ($template['label'] ?? ''),
            'template_key' => $templateKey,
            'bible' => [
                'id' => $draft['bible']->uuid ?? null,
                'concept' => $draft['bible']->concept ?? null,
                'aspect_ratio' => $draft['bible']->aspect_ratio ?? null,
            ],
            'style' => [
                'id' => $draft['style']->uuid ?? null,
                'visual_style' => $draft['style']->visual_style ?? null,
                'aspect_ratio' => $draft['style']->aspect_ratio ?? null,
            ],
            'characters_created' => $created,
            'characters_skipped' => $skipped,
            'plan' => [
                'id' => $plan->uuid,
                'status' => $plan->status,
                'current_version_id' => $version instanceof StoryPlanVersion ? $version->uuid : null,
                'review_status' => $version instanceof StoryPlanVersion ? $version->reviewStatus()->value : null,
            ],
            'plan_generation' => $outcome,
            'plan_estimate' => $estimate,
            'error' => $error,
            'next' => 'Review the story plan in the Story step',
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function log(User $actor, StoryPlan $plan, string $templateKey, array $input, string $outcome): void
    {
        $this->activity->log('story.quick_start', $plan, $actor, 'Quick start draft', [
            'template' => $templateKey,
            'duration_seconds' => (int) $input['duration_seconds'],
            'outcome' => $outcome,
            'topic' => mb_substr(trim((string) $input['topic']), 0, 80),
        ]);
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>|null
     */
    private function remembered(int $workspaceId, array $input): ?array
    {
        $key = $this->idempotencyKey($workspaceId, $input);
        if ($key === null) {
            return null;
        }
        $cached = Cache::get($key);

        return is_array($cached) ? $cached : null;
    }

    /**
     * @param  array<string, mixed>  $input
     * @param  array<string, mixed>  $result
     */
    private function remember(int $workspaceId, array $input, array $result): void
    {
        $key = $this->idempotencyKey($workspaceId, $input);
        if ($key === null) {
            return;
        }
        Cache::put($key, $result, now()->addMinutes(30));
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function idempotencyKey(int $workspaceId, array $input): ?string
    {
        $key = $input['idempotency_key'] ?? null;
        if (! is_string($key) || trim($key) === '') {
            return null;
        }

        return 'story-quick-start-result:'.$workspaceId.':'.trim($key);
    }
}
