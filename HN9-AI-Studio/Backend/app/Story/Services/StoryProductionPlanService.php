<?php

declare(strict_types=1);

namespace App\Story\Services;

use App\Contracts\Logging\ActivityLoggerInterface;
use App\Models\Project;
use App\Models\User;
use App\Story\Contracts\StoryPlanRepositoryInterface;
use App\Story\Contracts\StoryPlanVersionRepositoryInterface;
use App\Story\Contracts\StoryProductionPlanServiceInterface;
use App\Story\Contracts\StoryWorkspaceServiceInterface;
use App\Story\Enums\StoryPlanVersionStatus;
use App\Story\Enums\StoryProductionPlanStatus;
use App\Story\Enums\StoryReelStatus;
use App\Story\Enums\StorySceneStatus;
use App\Story\Exceptions\StoryException;
use App\Story\Exceptions\StoryProductionPlanException;
use App\Story\Models\StoryPlan;
use App\Story\Models\StoryPlanVersion;
use App\Story\Models\StoryProductionPlan;
use App\Story\Models\StoryProductionPlanScene;
use App\Story\Models\StoryProductionUnit;
use App\Story\Models\StoryReel;
use App\Story\Models\StoryScene;
use App\Story\Models\StorySceneVersion;
use App\Story\Models\StoryWorkspace;
use App\Story\Support\StoryGenerationUnitCalculator;
use App\Story\Support\StoryGenerationUnitSlot;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Builds production plans: Story Plan Version → its scenes → generation units.
 *
 * A plan and all of its rows are written in one transaction. The story plan row is
 * the lock for its plan history, and unique indexes back every rule a concurrent
 * request could race on (one current plan per story plan, one successor per plan,
 * one revision number per story plan). No provider is called here.
 */
final readonly class StoryProductionPlanService implements StoryProductionPlanServiceInterface
{
    public const EVENT_CREATED = 'story.production_plan.created';

    public const EVENT_REVISED = 'story.production_plan.revised';

    private const INSERT_CHUNK = 500;

    public function __construct(
        private StoryWorkspaceServiceInterface $workspaces,
        private StoryPlanRepositoryInterface $plans,
        private StoryPlanVersionRepositoryInterface $versions,
        private StoryGenerationUnitCalculator $calculator,
        private ActivityLoggerInterface $activity,
    ) {}

    public function listForProject(Project $project): Collection
    {
        return $this->scoped($project)
            ->with(['storyPlan', 'sourceVersion', 'reel', 'previousPlan'])
            ->withCount(['scenes', 'units'])
            ->orderByDesc('id')
            ->get();
    }

    public function getForProject(Project $project, string $planUuid): StoryProductionPlan
    {
        return $this->loadDetail($this->find($project, $planUuid));
    }

    public function currentForStoryPlan(Project $project, string $storyPlanUuid): ?StoryProductionPlan
    {
        $storyPlan = $this->storyPlan($this->workspaces->workspaceForProject($project), $storyPlanUuid);
        $plan = StoryProductionPlan::query()->where('current_for_story_plan_id', $storyPlan->id)->first();

        return $plan === null ? null : $this->loadDetail($plan);
    }

    public function sceneForPlan(Project $project, string $planUuid, string $sceneUuid): StoryProductionPlanScene
    {
        $plan = $this->find($project, $planUuid);

        $scene = StoryProductionPlanScene::query()
            ->where('story_production_plan_id', $plan->id)
            ->whereHas('scene', static fn (Builder $query) => $query->where('uuid', $sceneUuid))
            ->with(['scene', 'units' => static fn ($query) => $query->orderBy('sequence'), 'units.selectedVersion'])
            ->first()
            ?? throw StoryException::notFound('Production plan scene');

        return $scene->setRelation('plan', $plan);
    }

    public function createForVersion(Project $project, string $storyPlanUuid, string $versionUuid, ?User $actor = null): array
    {
        $workspace = $this->workspaces->workspaceForProject($project);
        $storyPlan = $this->storyPlan($workspace, $storyPlanUuid);
        $version = $this->versions->findByUuidForPlan($storyPlan, $versionUuid)
            ?? throw StoryException::notFound('Story plan version');
        $layout = $this->layout($workspace, $version);

        [$plan, $created] = $this->write($version, function () use ($workspace, $storyPlan, $version, $layout, $actor): array {
            $this->lockHistory($storyPlan);
            $current = StoryProductionPlan::query()
                ->where('current_for_story_plan_id', $storyPlan->id)
                ->lockForUpdate()
                ->first();
            if ($current !== null) {
                if ((int) $current->story_plan_version_id === (int) $version->id) {
                    return [$current, false];
                }

                throw StoryProductionPlanException::alreadyPlanned();
            }

            $plan = $this->insertPlan($workspace, $storyPlan, $version, $layout, null, $actor);
            $this->log(self::EVENT_CREATED, $plan, $actor, 'Production plan created', $layout);

            return [$plan, true];
        });

        return ['plan' => $this->loadDetail($plan), 'created' => $created];
    }

    public function revise(Project $project, string $planUuid, ?string $versionUuid = null, ?User $actor = null): array
    {
        $workspace = $this->workspaces->workspaceForProject($project);
        $source = $this->find($project, $planUuid);
        $storyPlan = StoryPlan::query()->whereKey($source->story_plan_id)->firstOrFail();
        $version = $versionUuid === null
            ? StoryPlanVersion::query()->whereKey($source->story_plan_version_id)->firstOrFail()
            : ($this->versions->findByUuidForPlan($storyPlan, $versionUuid) ?? throw StoryException::notFound('Story plan version'));
        $layout = $this->layout($workspace, $version);

        [$plan, $created] = $this->write($version, function () use ($workspace, $storyPlan, $version, $layout, $source, $actor): array {
            $this->lockHistory($storyPlan);
            $previous = StoryProductionPlan::query()->whereKey($source->id)->lockForUpdate()->firstOrFail();
            if (! $previous->isCurrent()) {
                $next = StoryProductionPlan::query()->where('previous_plan_id', $previous->id)->first();
                if ($next !== null && $next->isCurrent() && (int) $next->story_plan_version_id === (int) $version->id) {
                    return [$next, false];
                }

                throw StoryProductionPlanException::notCurrent();
            }

            $previous->forceFill([
                'status' => StoryProductionPlanStatus::Superseded->value,
                'current_for_story_plan_id' => null,
                'superseded_at' => now(),
            ])->save();

            $plan = $this->insertPlan($workspace, $storyPlan, $version, $layout, $previous, $actor);
            $this->log(self::EVENT_REVISED, $plan, $actor, 'Production plan revised', $layout, [
                'previous_revision' => $previous->revision,
            ]);

            return [$plan, true];
        });

        return ['plan' => $this->loadDetail($plan), 'created' => $created];
    }

    /**
     * Validates the source and computes every scene and unit before anything is written.
     *
     * @return array{reel: StoryReel, total: int, unit_count: int, scenes: list<array{scene: StoryScene, scene_version_id: int|null, sequence: int, start_second: int, duration_seconds: int, units: list<StoryGenerationUnitSlot>}>}
     */
    private function layout(StoryWorkspace $workspace, StoryPlanVersion $version): array
    {
        if ($version->statusEnum() !== StoryPlanVersionStatus::Completed) {
            throw StoryProductionPlanException::sourceNotReady('Only a finished story version can be planned for production.');
        }
        if (! $version->isApproved()) {
            throw StoryProductionPlanException::sourceNotReady('Approve this story version before preparing it for production.');
        }

        $reel = StoryReel::query()
            ->where('story_workspace_id', $workspace->id)
            ->where('source_plan_version_id', $version->id)
            ->first();
        if ($reel === null) {
            throw StoryProductionPlanException::sourceNotReady('Create the scenes for this story version before planning production.');
        }
        if ($reel->statusEnum() === StoryReelStatus::Archived) {
            throw StoryProductionPlanException::sourceNotReady('The scenes for this story version are archived.');
        }

        $scenes = StoryScene::query()
            ->where('story_reel_id', $reel->id)
            ->where('status', '!=', StorySceneStatus::Archived->value)
            ->orderBy('sequence')
            ->orderBy('id')
            ->get();
        if ($scenes->isEmpty()) {
            throw StoryProductionPlanException::sourceNotReady('This story version has no scenes to plan.');
        }

        $latestVersions = StorySceneVersion::query()
            ->whereIn('story_scene_id', $scenes->modelKeys())
            ->whereRaw('version = (select max(latest.version) from story_scene_versions as latest where latest.story_scene_id = story_scene_versions.story_scene_id)')
            ->pluck('id', 'story_scene_id');

        $rows = [];
        $cursor = 0;
        $unitCount = 0;
        foreach ($scenes->values() as $index => $scene) {
            $position = $index + 1;
            if ($scene->source_plan_version_id !== null && (int) $scene->source_plan_version_id !== (int) $version->id) {
                throw StoryProductionPlanException::invalidScene("Scene {$position} belongs to a different story version.");
            }

            $duration = (int) $scene->duration_seconds;
            try {
                $units = $this->calculator->split($duration);
            } catch (StoryProductionPlanException $exception) {
                throw StoryProductionPlanException::invalidSceneDuration("Scene {$position}: {$exception->getMessage()}");
            }

            $rows[] = [
                'scene' => $scene,
                'scene_version_id' => isset($latestVersions[$scene->id]) ? (int) $latestVersions[$scene->id] : null,
                'sequence' => $position,
                'start_second' => $cursor,
                'duration_seconds' => $duration,
                'units' => $units,
            ];
            $cursor += $duration;
            $unitCount += count($units);
        }

        return ['reel' => $reel, 'total' => $cursor, 'unit_count' => $unitCount, 'scenes' => $rows];
    }

    /**
     * @param  array{reel: StoryReel, total: int, unit_count: int, scenes: list<array<string, mixed>>}  $layout
     */
    private function insertPlan(
        StoryWorkspace $workspace,
        StoryPlan $storyPlan,
        StoryPlanVersion $version,
        array $layout,
        ?StoryProductionPlan $previous,
        ?User $actor,
    ): StoryProductionPlan {
        $revision = (int) StoryProductionPlan::query()->where('story_plan_id', $storyPlan->id)->max('revision') + 1;

        $plan = new StoryProductionPlan;
        $plan->forceFill([
            'story_workspace_id' => $workspace->id,
            'story_plan_id' => $storyPlan->id,
            'story_plan_version_id' => $version->id,
            'story_reel_id' => $layout['reel']->id,
            'previous_plan_id' => $previous?->id,
            'revision' => $revision,
            'status' => StoryProductionPlanStatus::Active->value,
            'current_for_story_plan_id' => $storyPlan->id,
            'unit_seconds' => $this->calculator->unitSeconds(),
            'total_duration_seconds' => $layout['total'],
            'created_by' => $actor?->id,
        ])->save();

        $now = $plan->created_at;
        $sceneRows = array_map(static fn (array $row): array => [
            'uuid' => (string) Str::uuid(),
            'story_production_plan_id' => $plan->id,
            'story_scene_id' => $row['scene']->id,
            'story_scene_version_id' => $row['scene_version_id'],
            'sequence' => $row['sequence'],
            'start_second' => $row['start_second'],
            'duration_seconds' => $row['duration_seconds'],
            'created_at' => $now,
            'updated_at' => $now,
        ], $layout['scenes']);
        foreach (array_chunk($sceneRows, self::INSERT_CHUNK) as $chunk) {
            StoryProductionPlanScene::query()->insert($chunk);
        }

        $sceneIds = StoryProductionPlanScene::query()
            ->where('story_production_plan_id', $plan->id)
            ->pluck('id', 'sequence');

        $unitRows = [];
        foreach ($layout['scenes'] as $row) {
            foreach ($row['units'] as $unit) {
                $unitRows[] = [
                    'uuid' => (string) Str::uuid(),
                    'story_production_plan_scene_id' => $sceneIds[$row['sequence']],
                    'sequence' => $unit->sequence,
                    'start_second' => $unit->startSecond,
                    'duration_seconds' => $unit->durationSeconds,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }
        foreach (array_chunk($unitRows, self::INSERT_CHUNK) as $chunk) {
            StoryProductionUnit::query()->insert($chunk);
        }

        return $plan;
    }

    /**
     * Runs the write in a transaction. A unique-index conflict means a concurrent request
     * committed first, so the write runs once more and sees that request's result.
     *
     * @param  callable(): array{0: StoryProductionPlan, 1: bool}  $write
     * @return array{0: StoryProductionPlan, 1: bool}
     */
    private function write(StoryPlanVersion $version, callable $write): array
    {
        try {
            try {
                return DB::transaction($write);
            } catch (UniqueConstraintViolationException) {
                return DB::transaction($write);
            }
        } catch (StoryException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            // A query exception's message embeds the full SQL with every bound row; keep only the driver error.
            $detail = $exception instanceof QueryException
                ? ($exception->getPrevious()?->getMessage() ?? 'Query failed.')
                : $exception->getMessage();
            Log::error('Production plan write failed and was rolled back.', [
                'story_plan_version' => $version->uuid,
                'exception' => $exception::class,
                'message' => Str::limit($detail, 300),
            ]);

            throw StoryProductionPlanException::buildFailed();
        }
    }

    private function lockHistory(StoryPlan $storyPlan): void
    {
        StoryPlan::query()->whereKey($storyPlan->id)->lockForUpdate()->first(['id']);
    }

    /**
     * @param  array{total: int, unit_count: int, scenes: list<array<string, mixed>>}  $layout
     * @param  array<string, mixed>  $extra
     */
    private function log(string $action, StoryProductionPlan $plan, ?User $actor, string $description, array $layout, array $extra = []): void
    {
        $this->activity->log($action, $plan, $actor, $description, [
            'revision' => $plan->revision,
            'scene_count' => count($layout['scenes']),
            'unit_count' => $layout['unit_count'],
            'total_duration_seconds' => $layout['total'],
            'unit_seconds' => $plan->unit_seconds,
            ...$extra,
        ]);
    }

    /**
     * @return Builder<StoryProductionPlan>
     */
    private function scoped(Project $project): Builder
    {
        $workspace = $this->workspaces->workspaceForProject($project);

        return StoryProductionPlan::query()->where('story_workspace_id', $workspace->id);
    }

    private function find(Project $project, string $planUuid): StoryProductionPlan
    {
        return $this->scoped($project)->where('uuid', $planUuid)->first()
            ?? throw StoryException::notFound('Production plan');
    }

    private function storyPlan(StoryWorkspace $workspace, string $storyPlanUuid): StoryPlan
    {
        return $this->plans->findByUuidForWorkspace($workspace, $storyPlanUuid)
            ?? throw StoryException::notFound('Story plan');
    }

    private function loadDetail(StoryProductionPlan $plan): StoryProductionPlan
    {
        return $plan->load([
            'workspace.project',
            'storyPlan',
            'sourceVersion',
            'reel',
            'previousPlan',
            'nextPlan',
            'scenes' => static fn ($query) => $query->orderBy('sequence'),
            'scenes.scene',
            'scenes.units' => static fn ($query) => $query->orderBy('sequence'),
            'scenes.units.selectedVersion',
        ]);
    }
}
