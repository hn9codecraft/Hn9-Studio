<?php

declare(strict_types=1);

namespace App\Story\Services;

use App\AI\Support\ProviderErrorSanitizer;
use App\Models\Project;
use App\Story\Enums\StoryReviewStatus;
use App\Story\Enums\StoryVideoJobStatus;
use App\Story\Models\StoryBible;
use App\Story\Models\StoryCharacter;
use App\Story\Models\StoryCharacterLock;
use App\Story\Models\StoryFinalRender;
use App\Story\Models\StoryPlanVersion;
use App\Story\Models\StoryProductionPlan;
use App\Story\Models\StoryProductionUnit;
use App\Story\Models\StoryScene;
use App\Story\Models\StoryStyleBible;
use App\Story\Models\StoryVideoGenerationJob;
use App\Story\Models\StoryWorkspace;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Read-only pipeline counts. Nothing here writes, spends, or calls a provider.
 */
final class StoryProgressService
{
    /**
     * @return array<string, mixed>
     */
    public function forProject(Project $project): array
    {
        $seconds = max(1, (int) config('story_progress.cache_seconds', 5));

        return Cache::remember('story-progress:'.$project->uuid, $seconds, fn (): array => $this->build($project));
    }

    /**
     * @return array<string, mixed>
     */
    public function build(Project $project): array
    {
        $workspace = StoryWorkspace::query()->where('project_id', $project->id)->first();
        $workspaceId = $workspace instanceof StoryWorkspace ? (int) $workspace->id : 0;
        $stages = [
            $this->story($workspaceId),
            $this->cast($workspaceId),
            $this->style($workspaceId),
            $this->plan($workspaceId),
            $this->production($workspaceId),
            $this->sound($workspaceId),
            $this->timeline($workspaceId),
            $this->renderStage($workspaceId),
            $this->reviewStage($workspaceId),
        ];

        return [
            'percent' => $this->percent($stages),
            'stages' => $stages,
            'render' => $this->render($workspaceId),
            'active' => $this->active($workspaceId, $this->render($workspaceId)),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function story(int $workspaceId): array
    {
        $concept = $workspaceId === 0
            ? false
            : StoryBible::query()->where('story_workspace_id', $workspaceId)->whereNotNull('concept')->where('concept', '!=', '')->exists();

        return $this->stage('story', 'Story', $concept ? 1 : 0, 1, null);
    }

    /**
     * @return array<string, mixed>
     */
    private function cast(int $workspaceId): array
    {
        if ($workspaceId === 0) {
            return $this->stage('cast', 'Cast', 0, 0, null);
        }
        $total = StoryCharacter::query()->where('story_workspace_id', $workspaceId)->where('status', '!=', 'archived')->count();
        $done = StoryCharacter::query()->where('story_workspace_id', $workspaceId)->where('status', '!=', 'archived')->whereNotNull('approved_reference_id')->count();
        $locks = StoryCharacterLock::query()->where('story_workspace_id', $workspaceId)->count();
        $stage = $this->stage('cast', 'Cast', $done, $total, null);
        $stage['locks'] = $locks;

        return $stage;
    }

    /**
     * @return array<string, mixed>
     */
    private function style(int $workspaceId): array
    {
        $bible = $workspaceId === 0 ? null : StoryStyleBible::query()->where('story_workspace_id', $workspaceId)->first();
        $configured = $bible instanceof StoryStyleBible && trim((string) $bible->visual_style) !== '';
        $approved = $bible instanceof StoryStyleBible && $bible->approved_reference_id !== null;
        $done = $configured && $approved ? 1 : 0;

        return $this->stage('style', 'Style', $done, 1, null);
    }

    /**
     * @return array<string, mixed>
     */
    private function plan(int $workspaceId): array
    {
        $approved = $workspaceId === 0
            ? false
            : StoryPlanVersion::query()->whereNotNull('approved_at')->whereHas('plan', static function ($query) use ($workspaceId): void {
                $query->where('story_workspace_id', $workspaceId);
            })->exists();

        return $this->stage('plan', 'Plan', $approved ? 1 : 0, 1, null);
    }

    /**
     * @return array<string, mixed>
     */
    private function production(int $workspaceId): array
    {
        $plan = $workspaceId === 0
            ? null
            : StoryProductionPlan::query()->where('story_workspace_id', $workspaceId)->where('status', 'active')->orderByDesc('id')->first();
        if (! $plan instanceof StoryProductionPlan) {
            return $this->stage('production', 'Production', 0, 0, null);
        }
        $unitIds = StoryProductionUnit::query()
            ->whereHas('planScene', static function ($query) use ($plan): void {
                $query->where('story_production_plan_id', $plan->id);
            })
            ->pluck('id');
        $total = $unitIds->count();
        $done = $total === 0
            ? 0
            : StoryProductionUnit::query()
                ->whereIn('id', $unitIds)
                ->where(function ($query): void {
                    $query->whereNotNull('selected_version_id')
                        ->orWhereHas('versions', static function ($query): void {
                            $query->where('review_status', StoryReviewStatus::Approved->value);
                        });
                })
                ->count();
        $failed = 0;
        if ($total > 0) {
            $latestIds = DB::table('story_video_generation_jobs')
                ->selectRaw('MAX(id) as id')
                ->whereIn('story_production_unit_id', $unitIds)
                ->groupBy('story_production_unit_id')
                ->pluck('id');
            $failed = StoryVideoGenerationJob::query()
                ->whereIn('id', $latestIds)
                ->whereIn('status', [StoryVideoJobStatus::Failed->value, StoryVideoJobStatus::Cancelled->value])
                ->count();
        }
        $hint = $failed > 0 ? $failed.' units need a retry' : null;
        $stage = $this->stage('production', 'Production', $done, $total, $hint);
        if ($failed > 0) {
            $stage['status'] = 'blocked';
            $stage['failed'] = $failed;
        }

        return $stage;
    }

    /**
     * @return array<string, mixed>
     */
    private function sound(int $workspaceId): array
    {
        $total = $this->sceneCount($workspaceId);
        $done = $total === 0
            ? 0
            : (int) DB::table('story_scene_audios')
                ->join('story_scenes', 'story_scenes.id', '=', 'story_scene_audios.story_scene_id')
                ->join('story_reels', 'story_reels.id', '=', 'story_scenes.story_reel_id')
                ->where('story_reels.story_workspace_id', $workspaceId)
                ->where('story_scene_audios.review_status', StoryReviewStatus::Approved->value)
                ->whereIn('story_scene_audios.role', ['voice', 'narration', 'dialogue'])
                ->distinct()
                ->count('story_scene_audios.story_scene_id');

        return $this->stage('sound', 'Sound', $done, $total, null);
    }

    /**
     * @return array<string, mixed>
     */
    private function timeline(int $workspaceId): array
    {
        $total = $this->sceneCount($workspaceId);
        $done = $total === 0
            ? 0
            : (int) DB::table('story_timeline_clips')
                ->join('story_scene_versions', 'story_scene_versions.id', '=', 'story_timeline_clips.story_scene_version_id')
                ->join('story_scenes', 'story_scenes.id', '=', 'story_scene_versions.story_scene_id')
                ->join('story_reels', 'story_reels.id', '=', 'story_scenes.story_reel_id')
                ->where('story_reels.story_workspace_id', $workspaceId)
                ->distinct('story_scenes.id')
                ->count('story_scenes.id');

        return $this->stage('timeline', 'Timeline', $done, $total, null);
    }

    /**
     * @return array<string, mixed>
     */
    private function renderStage(int $workspaceId): array
    {
        $render = $this->latestRender($workspaceId);
        $done = $render instanceof StoryFinalRender && $render->status === StoryVideoJobStatus::Completed->value ? 1 : 0;

        return $this->stage('render', 'Render', $done, $render instanceof StoryFinalRender || $this->sceneCount($workspaceId) > 0 ? 1 : 0, null);
    }

    /**
     * @return array<string, mixed>
     */
    private function reviewStage(int $workspaceId): array
    {
        $render = $this->latestRender($workspaceId);
        $done = $render instanceof StoryFinalRender && $render->review_status === StoryReviewStatus::Approved->value ? 1 : 0;

        return $this->stage('review', 'Review', $done, 1, null);
    }

    /**
     * @return array<string, mixed>
     */
    private function render(int $workspaceId): array
    {
        $render = $this->latestRender($workspaceId);
        if (! $render instanceof StoryFinalRender) {
            return [
                'status' => null,
                'render_uuid' => null,
                'queued_at' => null,
                'started_at' => null,
                'elapsed_seconds' => null,
                'finished_at' => null,
                'error' => null,
            ];
        }
        $status = (string) $render->status;
        $started = $render->started_at;
        $elapsed = null;
        if (in_array($status, [StoryVideoJobStatus::Queued->value, StoryVideoJobStatus::Rendering->value, StoryVideoJobStatus::Processing->value], true) && $started !== null) {
            $elapsed = max(0, (int) $started->diffInSeconds(now()));
        }
        $error = null;
        if ($status === StoryVideoJobStatus::Failed->value) {
            $safe = ProviderErrorSanitizer::message($render->error_message, 'The render failed.');
            $error = str_contains($safe, '/') || str_contains($safe, '\\') ? 'The render failed.' : $safe;
        }

        return [
            'status' => $status,
            'render_uuid' => $render->uuid,
            'queued_at' => $render->queued_at?->toIso8601String(),
            'started_at' => $started?->toIso8601String(),
            'elapsed_seconds' => $elapsed,
            'finished_at' => in_array($status, [StoryVideoJobStatus::Completed->value, StoryVideoJobStatus::Failed->value], true)
                ? $render->updated_at?->toIso8601String()
                : null,
            'error' => $error,
        ];
    }

    /**
     * @param  array<string, mixed>  $render
     */
    private function active(int $workspaceId, array $render): bool
    {
        if (in_array($render['status'], [StoryVideoJobStatus::Queued->value, StoryVideoJobStatus::Rendering->value, StoryVideoJobStatus::Processing->value], true)) {
            return true;
        }
        if ($workspaceId === 0) {
            return false;
        }

        return StoryVideoGenerationJob::query()
            ->where('story_workspace_id', $workspaceId)
            ->whereIn('status', [
                StoryVideoJobStatus::Queued->value,
                StoryVideoJobStatus::Submitted->value,
                StoryVideoJobStatus::Processing->value,
                StoryVideoJobStatus::Rendering->value,
            ])
            ->exists();
    }

    private function sceneCount(int $workspaceId): int
    {
        if ($workspaceId === 0) {
            return 0;
        }

        return StoryScene::query()->whereHas('reel', static function ($query) use ($workspaceId): void {
            $query->where('story_workspace_id', $workspaceId);
        })->count();
    }

    private function latestRender(int $workspaceId): ?StoryFinalRender
    {
        if ($workspaceId === 0) {
            return null;
        }

        return StoryFinalRender::query()
            ->whereHas('reel', static function ($query) use ($workspaceId): void {
                $query->where('story_workspace_id', $workspaceId);
            })
            ->orderByDesc('id')
            ->first();
    }

    /**
     * @param  list<array<string, mixed>>  $stages
     */
    private function percent(array $stages): int
    {
        $weights = (array) config('story_progress.weights', []);
        $weighted = 0.0;
        $total = 0.0;
        foreach ($stages as $stage) {
            $weight = (int) ($weights[$stage['key']] ?? 0);
            $total += $weight;
            $ratio = (int) $stage['total'] > 0 ? min(1, ((int) $stage['done']) / ((int) $stage['total'])) : 0;
            $weighted += $weight * $ratio;
        }

        return $total > 0 ? (int) round(($weighted / $total) * 100) : 0;
    }

    /**
     * @return array<string, mixed>
     */
    private function stage(string $key, string $label, int $done, int $total, ?string $hint): array
    {
        $status = 'not_started';
        if ($total > 0 && $done >= $total) {
            $status = 'done';
        } elseif ($done > 0) {
            $status = 'in_progress';
        }

        return [
            'key' => $key,
            'label' => $label,
            'done' => $done,
            'total' => $total,
            'status' => $status,
            'hint' => $hint,
        ];
    }
}
