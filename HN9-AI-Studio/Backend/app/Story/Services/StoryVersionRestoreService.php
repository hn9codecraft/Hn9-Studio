<?php

declare(strict_types=1);

namespace App\Story\Services;

use App\Contracts\Logging\ActivityLoggerInterface;
use App\Models\Project;
use App\Models\User;
use App\Story\Contracts\StoryProductionPlanServiceInterface;
use App\Story\Enums\StoryVideoJobStatus;
use App\Story\Exceptions\StoryException;
use App\Story\Models\StoryFinalRender;
use App\Story\Models\StoryProductionPlanScene;
use App\Story\Models\StoryProductionUnit;
use App\Story\Models\StoryReel;
use App\Story\Models\StoryScene;
use App\Story\Models\StorySceneAssembly;
use App\Story\Models\StorySceneVersion;
use App\Story\Models\StoryTimelineClip;
use App\Story\Models\StoryVersionRestore;
use App\Story\Models\StoryVideoGenerationJob;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Restores an older scene video without deleting anything and without calling a provider.
 * Assemblies gain a current pointer through selected_at because the table had none;
 * before any selection, the latest completed assembly stays current.
 */
final class StoryVersionRestoreService
{
    public const NOT_RESTORABLE = 'story_version_not_restorable';

    public const RENDER_IN_PROGRESS = 'story_render_in_progress';

    public function __construct(
        private StoryProductionPlanServiceInterface $plans,
        private StoryProductionUnitVersionService $units,
        private StoryTimelineService $timelines,
        private ActivityLoggerInterface $activity,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function listForScene(Project $project, string $planUuid, string $sceneUuid): array
    {
        $scene = $this->plans->sceneForPlan($project, $planUuid, $sceneUuid);
        $scene->loadMissing(['plan', 'units' => static fn ($query) => $query->orderBy('sequence'), 'scene']);
        $current = $this->currentAssembly($scene);
        $units = [];
        foreach ($scene->units as $unit) {
            if (! $unit instanceof StoryProductionUnit) {
                continue;
            }
            $listed = $this->units->listForUnit($project, $planUuid, $unit->uuid);
            $versions = [];
            foreach ($listed['versions'] as $row) {
                if (! is_array($row)) {
                    continue;
                }
                $versions[] = [
                    'uuid' => $row['id'] ?? null,
                    'created_at' => $row['created_at'] ?? null,
                    'duration_seconds' => $row['output_duration_seconds'] ?? $row['duration_seconds'] ?? null,
                    'status' => $row['status'] ?? null,
                    'selected' => (bool) ($row['selected'] ?? false),
                    'label' => $row['label'] ?? null,
                    'preview_url' => ! empty($row['preview_available'])
                        ? '/api/v1/story/projects/'.$project->uuid.'/production-plans/'.$planUuid.'/units/'.$unit->uuid.'/versions/'.$row['id'].'/file'
                        : null,
                ];
            }
            $units[] = [
                'uuid' => $unit->uuid,
                'sequence' => $unit->sequence,
                'versions' => $versions,
            ];
        }

        $assemblies = StorySceneAssembly::query()
            ->where('story_production_plan_scene_id', $scene->id)
            ->orderBy('id')
            ->get()
            ->map(function (StorySceneAssembly $assembly) use ($project, $planUuid, $sceneUuid, $current): array {
                $ready = $this->fileReady($assembly);

                return [
                    'uuid' => $assembly->uuid,
                    'created_at' => $assembly->created_at?->toIso8601String(),
                    'duration_seconds' => $assembly->duration_seconds === null
                        ? (int) $assembly->expected_duration_seconds
                        : (float) $assembly->duration_seconds,
                    'status' => $assembly->status,
                    'current' => $current instanceof StorySceneAssembly && (int) $current->id === (int) $assembly->id,
                    'preview_url' => $ready
                        ? '/api/v1/story/projects/'.$project->uuid.'/production-plans/'.$planUuid.'/scenes/'.$sceneUuid.'/assemblies/'.$assembly->uuid.'/file'
                        : null,
                ];
            })
            ->values()
            ->all();

        return [
            'units' => $units,
            'assemblies' => $assemblies,
            'history' => $this->history($scene),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function restoreAssembly(Project $project, string $planUuid, string $sceneUuid, string $assemblyUuid, User $actor): array
    {
        $scene = $this->plans->sceneForPlan($project, $planUuid, $sceneUuid);
        $assembly = StorySceneAssembly::query()
            ->where('uuid', $assemblyUuid)
            ->where('story_production_plan_scene_id', $scene->id)
            ->first();
        if (! $assembly instanceof StorySceneAssembly) {
            throw StoryException::notFound('Scene version');
        }
        if (! $assembly->isComplete() || ! $this->fileReady($assembly)) {
            throw new StoryException(
                'This version cannot be restored because it is not finished or its video file is missing.',
                self::NOT_RESTORABLE,
                422,
            );
        }

        $scene->loadMissing('scene.reel', 'plan');
        $reel = $scene->scene?->reel;
        $this->assertRenderIdle($reel);
        $current = $this->currentAssembly($scene);
        $stale = $this->renderStale($reel);
        if ($current instanceof StorySceneAssembly && (int) $current->id === (int) $assembly->id) {
            return $this->result(false, 'assembly', $assembly->uuid, $current->uuid, 0, false, $stale);
        }

        $durationMs = $this->durationMs($assembly->duration_seconds, (int) $assembly->expected_duration_seconds);
        $storyScene = $scene->scene;
        if (! $storyScene instanceof StoryScene) {
            throw StoryException::notFound('Scene');
        }

        $retarget = ['updated' => 0, 'trim_adjusted' => false];
        DB::transaction(function () use ($assembly, $scene, $storyScene, $durationMs, $actor, $current, &$retarget): void {
            $this->markAssemblyCurrent($assembly);
            $retarget = $this->timelines->retargetSceneVideo(
                $storyScene,
                $assembly->id,
                null,
                'videos',
                (string) $assembly->path,
                $durationMs,
            );
            $this->record(
                (int) $assembly->story_workspace_id,
                'assembly',
                $assembly->id,
                $current?->id,
                $actor,
                (int) $retarget['updated'],
                $assembly,
            );
        });

        return $this->result(
            true,
            'assembly',
            $assembly->uuid,
            $current?->uuid,
            (int) $retarget['updated'],
            (bool) $retarget['trim_adjusted'],
            $stale,
        );
    }

    /**
     * Review scene versions have no current column. selected_at is the playback pointer;
     * the latest version number used by review is left unchanged.
     *
     * @return array<string, mixed>
     */
    public function restoreSceneVersion(Project $project, string $reelUuid, string $sceneUuid, string $versionUuid, User $actor): array
    {
        $scene = StoryScene::query()
            ->where('uuid', $sceneUuid)
            ->whereHas('reel', static function ($query) use ($project, $reelUuid): void {
                $query->where('uuid', $reelUuid)
                    ->whereHas('workspace', static function ($workspace) use ($project): void {
                        $workspace->where('project_id', $project->id);
                    });
            })
            ->first();
        if (! $scene instanceof StoryScene) {
            throw StoryException::notFound('Scene version');
        }
        $version = StorySceneVersion::query()
            ->where('uuid', $versionUuid)
            ->where('story_scene_id', $scene->id)
            ->first();
        if (! $version instanceof StorySceneVersion) {
            throw StoryException::notFound('Scene version');
        }

        $file = $this->versionFile($scene, $version);
        if ($file === null) {
            throw new StoryException(
                'This version cannot be restored because it is not finished or its video file is missing.',
                self::NOT_RESTORABLE,
                422,
            );
        }

        $scene->loadMissing('reel.workspace');
        $reel = $scene->reel;
        $this->assertRenderIdle($reel);
        $current = $this->currentSceneVersion($scene);
        $stale = $this->renderStale($reel);
        if ($current instanceof StorySceneVersion && (int) $current->id === (int) $version->id) {
            return $this->result(false, 'scene_version', $version->uuid, $current->uuid, 0, false, $stale);
        }

        $retarget = ['updated' => 0, 'trim_adjusted' => false];
        DB::transaction(function () use ($scene, $version, $file, $actor, $current, &$retarget): void {
            StorySceneVersion::query()
                ->where('story_scene_id', $scene->id)
                ->whereKeyNot($version->id)
                ->whereNotNull('selected_at')
                ->update(['selected_at' => null]);
            $version->forceFill(['selected_at' => now()])->save();
            $retarget = $this->timelines->retargetSceneVideo(
                $scene,
                null,
                $version->id,
                $file['disk'],
                $file['path'],
                $file['duration_ms'],
            );
            $this->record(
                (int) ($scene->reel?->story_workspace_id ?? 0),
                'scene_version',
                $version->id,
                $current?->id,
                $actor,
                (int) $retarget['updated'],
                $version,
            );
        });

        return $this->result(
            true,
            'scene_version',
            $version->uuid,
            $current?->uuid,
            (int) $retarget['updated'],
            (bool) $retarget['trim_adjusted'],
            $stale,
        );
    }

    private function currentAssembly(StoryProductionPlanScene $scene): ?StorySceneAssembly
    {
        $rows = StorySceneAssembly::query()
            ->where('story_production_plan_scene_id', $scene->id)
            ->orderBy('id')
            ->get();
        $selected = $rows
            ->filter(static fn (StorySceneAssembly $row): bool => $row->isComplete() && $row->selected_at !== null)
            ->sortByDesc(static fn (StorySceneAssembly $row): string => sprintf('%010d-%010d', $row->selected_at?->getTimestamp() ?? 0, $row->id))
            ->first();
        if ($selected instanceof StorySceneAssembly) {
            return $selected;
        }
        $last = $rows->last(static fn (StorySceneAssembly $row): bool => $row->isComplete());

        return $last instanceof StorySceneAssembly ? $last : null;
    }

    private function markAssemblyCurrent(StorySceneAssembly $assembly): void
    {
        StorySceneAssembly::query()
            ->where('story_production_plan_scene_id', $assembly->story_production_plan_scene_id)
            ->whereKeyNot($assembly->id)
            ->whereNotNull('selected_at')
            ->update(['selected_at' => null]);
        $assembly->forceFill(['selected_at' => now()])->save();
    }

    private function currentSceneVersion(StoryScene $scene): ?StorySceneVersion
    {
        $selected = StorySceneVersion::query()
            ->where('story_scene_id', $scene->id)
            ->whereNotNull('selected_at')
            ->orderByDesc('selected_at')
            ->orderByDesc('id')
            ->first();
        if ($selected instanceof StorySceneVersion) {
            return $selected;
        }

        $pointedId = StoryTimelineClip::query()
            ->where('media_kind', 'video')
            ->whereNotNull('story_scene_version_id')
            ->whereHas('sceneVersion', static function ($query) use ($scene): void {
                $query->where('story_scene_id', $scene->id);
            })
            ->orderByDesc('id')
            ->value('story_scene_version_id');
        if ($pointedId !== null) {
            $pointed = StorySceneVersion::query()->find($pointedId);
            if ($pointed instanceof StorySceneVersion) {
                return $pointed;
            }
        }

        return StorySceneVersion::query()
            ->where('story_scene_id', $scene->id)
            ->orderByDesc('version')
            ->first();
    }

    /**
     * @return array{disk: string, path: string, duration_ms: int}|null
     */
    private function versionFile(StoryScene $scene, StorySceneVersion $version): ?array
    {
        $job = StoryVideoGenerationJob::query()
            ->where('story_scene_id', $scene->id)
            ->where('status', StoryVideoJobStatus::Completed->value)
            ->orderByDesc('id')
            ->get()
            ->first(static function (StoryVideoGenerationJob $job) use ($version): bool {
                return ($job->provider_metadata['version_id'] ?? null) === $version->uuid;
            });
        if (! $job instanceof StoryVideoGenerationJob) {
            return null;
        }
        $storage = $job->provider_metadata['storage'] ?? null;
        if (! is_array($storage)) {
            return null;
        }
        $disk = (string) ($storage['disk'] ?? '');
        $path = (string) ($storage['path'] ?? '');
        if ($disk === '' || $path === '' || str_contains($path, '..') || ! Storage::disk($disk)->exists($path) || Storage::disk($disk)->size($path) < 1) {
            return null;
        }
        $seconds = $job->provider_metadata['duration_seconds'] ?? $scene->duration_seconds;

        return [
            'disk' => $disk,
            'path' => $path,
            'duration_ms' => $this->durationMs(is_numeric($seconds) ? (float) $seconds : null, (int) $scene->duration_seconds),
        ];
    }

    private function assertRenderIdle(?StoryReel $reel): void
    {
        if (! $reel instanceof StoryReel) {
            return;
        }
        $active = StoryFinalRender::query()
            ->where('story_reel_id', $reel->id)
            ->whereIn('status', StoryRenderService::ACTIVE)
            ->first();
        if ($active instanceof StoryFinalRender) {
            throw new StoryException(
                'A render is already in progress for this video.',
                self::RENDER_IN_PROGRESS,
                409,
                ['render_id' => $active->uuid],
            );
        }
    }

    private function renderStale(?StoryReel $reel): bool
    {
        if (! $reel instanceof StoryReel) {
            return false;
        }

        return StoryFinalRender::query()->where('story_reel_id', $reel->id)->exists();
    }

    private function fileReady(StorySceneAssembly $assembly): bool
    {
        $path = (string) $assembly->path;

        return $assembly->isComplete()
            && $assembly->disk === 'videos'
            && $path !== ''
            && ! str_contains($path, '..')
            && ! str_contains($path, '://')
            && Storage::disk('videos')->exists($path)
            && Storage::disk('videos')->size($path) > 0;
    }

    private function durationMs(mixed $seconds, int $fallback): int
    {
        $value = is_numeric($seconds) ? (float) $seconds : (float) $fallback;

        return max(1, (int) round($value * 1000));
    }

    private function record(
        int $workspaceId,
        string $type,
        int $subjectId,
        ?int $previousId,
        User $actor,
        int $clips,
        StorySceneAssembly|StorySceneVersion $subject,
    ): void {
        if ($workspaceId < 1) {
            return;
        }
        $row = new StoryVersionRestore;
        $row->forceFill([
            'story_workspace_id' => $workspaceId,
            'subject_type' => $type,
            'subject_id' => $subjectId,
            'previous_id' => $previousId,
            'restored_by' => $actor->id,
            'timeline_clips_updated' => $clips,
            'created_at' => now(),
        ])->save();

        $previousUuid = null;
        if ($previousId !== null) {
            $previousUuid = $type === 'assembly'
                ? StorySceneAssembly::query()->whereKey($previousId)->value('uuid')
                : StorySceneVersion::query()->whereKey($previousId)->value('uuid');
        }
        $this->activity->log('story.version_restored', $subject, $actor, 'Version restored', [
            'subject_type' => $type,
            'from' => $previousUuid,
            'to' => $subject->uuid,
        ]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function history(StoryProductionPlanScene $scene): array
    {
        $scene->loadMissing('plan', 'scene.reel');
        $workspaceId = (int) ($scene->plan?->story_workspace_id ?? $scene->scene?->reel?->story_workspace_id ?? 0);
        if ($workspaceId < 1) {
            return [];
        }
        $assemblyIds = StorySceneAssembly::query()
            ->where('story_production_plan_scene_id', $scene->id)
            ->pluck('id');

        return StoryVersionRestore::query()
            ->where('story_workspace_id', $workspaceId)
            ->where('subject_type', 'assembly')
            ->whereIn('subject_id', $assemblyIds)
            ->orderByDesc('id')
            ->limit(20)
            ->get()
            ->map(static function (StoryVersionRestore $row): array {
                return [
                    'uuid' => $row->uuid,
                    'subject_type' => $row->subject_type,
                    'subject_uuid' => StorySceneAssembly::query()->whereKey($row->subject_id)->value('uuid'),
                    'previous_uuid' => $row->previous_id === null
                        ? null
                        : StorySceneAssembly::query()->whereKey($row->previous_id)->value('uuid'),
                    'timeline_clips_updated' => $row->timeline_clips_updated,
                    'created_at' => $row->created_at?->toIso8601String(),
                ];
            })
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function result(
        bool $changed,
        string $type,
        string $uuid,
        ?string $previousUuid,
        int $clips,
        bool $trimAdjusted,
        bool $renderStale,
    ): array {
        return [
            'changed' => $changed,
            'restored' => ['type' => $type, 'uuid' => $uuid],
            'previous' => ['type' => $type, 'uuid' => $previousUuid],
            'timeline_clips_updated' => $clips,
            'trim_adjusted' => $trimAdjusted,
            'render_stale' => $renderStale,
        ];
    }
}
