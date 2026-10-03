<?php

declare(strict_types=1);

namespace App\Story\Services;

use App\Contracts\Logging\ActivityLoggerInterface;
use App\Jobs\ProcessStorySceneAssembly;
use App\Models\Project;
use App\Models\User;
use App\Story\Contracts\StoryProductionPlanServiceInterface;
use App\Story\Contracts\StoryProductionUnitVersionServiceInterface;
use App\Story\Contracts\StorySceneAssemblyServiceInterface;
use App\Story\Enums\StoryVideoJobStatus;
use App\Story\Exceptions\StoryException;
use App\Story\Media\StoryMediaSegment;
use App\Story\Media\StoryMediaToolkit;
use App\Story\Models\StoryProductionPlanScene;
use App\Story\Models\StoryProductionUnit;
use App\Story\Models\StorySceneAssembly;
use App\Story\Models\StoryVideoGenerationJob;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * Joins the selected version of every unit in a production scene into one
 * scene video. Generation, review and provider choice stay where they are.
 */
final class StorySceneAssemblyService implements StorySceneAssemblyServiceInterface
{
    public const NOT_READY = 'SCENE_NOT_READY';

    public const EVENT_REQUESTED = 'story.scene_assembly.requested';

    public const EVENT_STARTED = 'story.scene_assembly.started';

    public const EVENT_COMPLETED = 'story.scene_assembly.completed';

    public const EVENT_FAILED = 'story.scene_assembly.failed';

    public const EVENT_RETRIED = 'story.scene_assembly.retried';

    public const EVENT_VERSION = 'story.scene_assembly.version_created';

    public function __construct(
        private StoryProductionPlanServiceInterface $plans,
        private StoryProductionUnitVersionServiceInterface $versions,
        private StoryMediaToolkit $media,
        private ActivityLoggerInterface $activity,
    ) {}

    public function assemble(Project $project, string $planUuid, string $sceneUuid, User $actor): array
    {
        $scene = $this->scene($project, $planUuid, $sceneUuid);
        $built = $this->collect($scene);
        $existing = $this->findIntent($scene, $built['key']);

        if ($existing instanceof StorySceneAssembly && $existing->isComplete()) {
            return ['created' => false, 'assembly' => $this->present($existing)];
        }
        if ($existing instanceof StorySceneAssembly && $this->running($existing)) {
            return ['created' => false, 'assembly' => $this->present($existing)];
        }
        if (! $built['ready']) {
            $this->remember($scene, $built, $actor, StoryVideoJobStatus::Failed, 'Some video parts are not ready yet.', self::NOT_READY);
            throw new StoryException('Some video parts are not ready yet.', self::NOT_READY, 422);
        }

        $assembly = $existing instanceof StorySceneAssembly
            ? $this->reopen($existing, $actor)
            : $this->insert($scene, $built, $actor);
        $created = $existing === null && $assembly->wasRecentlyCreated;
        $this->dispatch($assembly);

        return ['created' => $created, 'assembly' => $this->present($assembly->fresh() ?? $assembly)];
    }

    public function listForScene(Project $project, string $planUuid, string $sceneUuid): array
    {
        $scene = $this->scene($project, $planUuid, $sceneUuid);
        $rows = StorySceneAssembly::query()
            ->where('story_production_plan_scene_id', $scene->id)
            ->orderBy('id')
            ->get();

        return [
            'current' => $this->presentCurrent($rows->last(static fn (StorySceneAssembly $row): bool => $row->isComplete())),
            'assemblies' => $rows->map(fn (StorySceneAssembly $row): array => $this->present($row))->values()->all(),
        ];
    }

    public function workspace(Project $project, string $planUuid, string $sceneUuid): array
    {
        $scene = $this->scene($project, $planUuid, $sceneUuid);
        $scene->loadMissing(['units' => static fn ($query) => $query->orderBy('sequence')]);
        $clips = [];
        foreach ($scene->units as $unit) {
            if (! $unit instanceof StoryProductionUnit) {
                continue;
            }
            $listed = $this->versions->listForUnit($project, $planUuid, $unit->uuid);
            $active = StoryVideoGenerationJob::query()
                ->where('story_production_unit_id', $unit->id)
                ->whereIn('status', [
                    StoryVideoJobStatus::Queued->value,
                    StoryVideoJobStatus::Submitted->value,
                    StoryVideoJobStatus::Processing->value,
                ])
                ->orderByDesc('id')
                ->first();
            $clips[] = [
                'id' => $unit->uuid,
                'sequence' => $unit->sequence,
                'start_second' => $unit->start_second,
                'duration_seconds' => $unit->duration_seconds,
                'end_second' => $unit->endSecond(),
                'message' => $listed['message'],
                'versions' => array_map(static function (array $row): array {
                    unset($row['provider'], $row['model']);

                    return $row;
                }, $listed['versions']),
                'active_generation' => $active instanceof StoryVideoGenerationJob
                    ? ['id' => $active->uuid, 'status' => $active->statusEnum()->value]
                    : null,
            ];
        }

        $listedAssemblies = $this->listForScene($project, $planUuid, $sceneUuid);

        return [
            'plan_id' => $scene->plan?->uuid,
            'duration_seconds' => (int) $scene->duration_seconds,
            'clips' => $clips,
            'current' => $listedAssemblies['current'],
            'assemblies' => $listedAssemblies['assemblies'],
        ];
    }

    public function show(Project $project, string $planUuid, string $sceneUuid, string $assemblyUuid): array
    {
        return $this->present($this->owned($project, $planUuid, $sceneUuid, $assemblyUuid));
    }

    public function file(Project $project, string $planUuid, string $sceneUuid, string $assemblyUuid): StreamedResponse
    {
        $assembly = $this->owned($project, $planUuid, $sceneUuid, $assemblyUuid);
        if (! $this->fileReady($assembly)) {
            throw new StoryException('This scene video is not ready yet.', 'SCENE_ASSEMBLY_NOT_READY', 422);
        }

        return Storage::disk('videos')->response((string) $assembly->path, 'scene.mp4', [
            'Content-Type' => is_string($assembly->mime) && $assembly->mime !== '' ? $assembly->mime : 'video/mp4',
        ]);
    }

    public function current(Project $project, string $planUuid, string $sceneUuid): ?array
    {
        return $this->listForScene($project, $planUuid, $sceneUuid)['current'];
    }

    public function execute(int $assemblyId): void
    {
        $before = StorySceneAssembly::query()->find($assemblyId);
        $retry = $before instanceof StorySceneAssembly && $before->statusEnum() === StoryVideoJobStatus::Failed;
        $claimed = StorySceneAssembly::query()
            ->whereKey($assemblyId)
            ->whereIn('status', [StoryVideoJobStatus::Queued->value, StoryVideoJobStatus::Failed->value])
            ->update([
                'status' => StoryVideoJobStatus::Processing->value,
                'started_at' => now(),
                'error_code' => null,
                'error_message' => null,
                'failed_at' => null,
            ]);
        $assembly = StorySceneAssembly::query()->find($assemblyId);
        if (! $assembly instanceof StorySceneAssembly || $claimed !== 1) {
            return;
        }

        $actor = $assembly->requester;
        if ($retry) {
            $this->log($assembly, $actor, self::EVENT_RETRIED, 'Scene assembly tried again');
        }
        $this->log($assembly, $actor, self::EVENT_STARTED, 'Scene assembly started');

        try {
            $this->render($assembly);
        } catch (Throwable $exception) {
            $this->fail($assembly, $exception);
        }
    }

    public function recoverStale(): int
    {
        $cutoff = now()->subSeconds(max(60, (int) config('story_video.ffmpeg.timeout_seconds', 900)));
        $rows = StorySceneAssembly::query()
            ->whereIn('status', [StoryVideoJobStatus::Queued->value, StoryVideoJobStatus::Processing->value])
            ->where('updated_at', '<', $cutoff)
            ->orderBy('id')
            ->limit(50)
            ->get();

        foreach ($rows as $assembly) {
            $this->discard($assembly);
            $assembly->forceFill([
                'status' => StoryVideoJobStatus::Failed->value,
                'error_code' => 'SCENE_ASSEMBLY_INTERRUPTED',
                'error_message' => 'The scene video build was interrupted. You can try again.',
                'failed_at' => now(),
            ])->save();
            $this->log($assembly, $assembly->requester, self::EVENT_FAILED, 'Scene assembly failed');
        }

        $this->sweepScratch($cutoff);

        return $rows->count();
    }

    /**
     * @param  array{key: string, ready: bool, aspect: string, sources: list<array<string, mixed>>}  $built
     */
    private function insert(StoryProductionPlanScene $scene, array $built, User $actor): StorySceneAssembly
    {
        try {
            return $this->store($scene, $built, $actor, StoryVideoJobStatus::Queued, null, null);
        } catch (UniqueConstraintViolationException) {
            $existing = $this->findIntent($scene, $built['key']);
            if ($existing instanceof StorySceneAssembly) {
                return $existing;
            }

            return $this->store($scene, $built, $actor, StoryVideoJobStatus::Queued, null, null);
        }
    }

    private function reopen(StorySceneAssembly $assembly, User $actor): StorySceneAssembly
    {
        // Leave the failed status in place so execute() can record one retry
        // before it claims the row. Clearing it here would look like a new job.
        $assembly->forceFill([
            'requested_by' => $actor->id,
        ])->save();

        return $assembly;
    }

    /**
     * @param  array{key: string, ready: bool, aspect: string, sources: list<array<string, mixed>>}  $built
     */
    private function remember(StoryProductionPlanScene $scene, array $built, User $actor, StoryVideoJobStatus $status, string $message, string $code): StorySceneAssembly
    {
        $existing = $this->findIntent($scene, $built['key']);
        if ($existing instanceof StorySceneAssembly) {
            return $existing;
        }

        try {
            $row = $this->store($scene, $built, $actor, $status, $message, $code);
        } catch (UniqueConstraintViolationException) {
            $existing = $this->findIntent($scene, $built['key']);
            if ($existing instanceof StorySceneAssembly) {
                return $existing;
            }
            $row = $this->store($scene, $built, $actor, $status, $message, $code);
        }
        $this->log($row, $actor, self::EVENT_FAILED, 'Scene assembly failed');

        return $row;
    }

    /**
     * @param  array{key: string, ready: bool, aspect: string, sources: list<array<string, mixed>>}  $built
     */
    private function store(StoryProductionPlanScene $scene, array $built, User $actor, StoryVideoJobStatus $status, ?string $message, ?string $code): StorySceneAssembly
    {
        $row = new StorySceneAssembly;
        $row->forceFill([
            'story_workspace_id' => $scene->plan->story_workspace_id,
            'story_production_plan_scene_id' => $scene->id,
            'requested_by' => $actor->id,
            'idempotency_key' => $built['key'],
            'status' => $status->value,
            'snapshot' => $this->snapshot($scene, $built),
            'expected_duration_seconds' => (int) $scene->duration_seconds,
            'error_code' => $code,
            'error_message' => $message,
            'failed_at' => $status === StoryVideoJobStatus::Failed ? now() : null,
        ])->save();
        if ($status === StoryVideoJobStatus::Queued) {
            $this->log($row, $actor, self::EVENT_REQUESTED, 'Scene assembly requested');
        }

        return $row;
    }

    private function dispatch(StorySceneAssembly $assembly): void
    {
        if ($assembly->isComplete() || $assembly->statusEnum() === StoryVideoJobStatus::Processing) {
            return;
        }
        if ((bool) config('story_video.queue.enabled', false)) {
            ProcessStorySceneAssembly::dispatch($assembly->id);

            return;
        }

        $this->execute($assembly->id);
    }

    private function render(StorySceneAssembly $assembly): void
    {
        $snapshot = is_array($assembly->snapshot) ? $assembly->snapshot : [];
        $units = is_array($snapshot['units'] ?? null) ? $snapshot['units'] : [];
        $aspect = is_string($snapshot['aspect_ratio'] ?? null) ? $snapshot['aspect_ratio'] : '16:9';
        $expected = (int) $assembly->expected_duration_seconds;
        if ($units === [] || $expected < 1) {
            throw new StoryException('Some video parts are not ready yet.', self::NOT_READY, 422);
        }

        $segments = [];
        foreach ($units as $unit) {
            if (! is_array($unit)) {
                throw new StoryException('Some video parts are not ready yet.', self::NOT_READY, 422);
            }
            $path = (string) ($unit['path'] ?? '');
            $disk = (string) ($unit['disk'] ?? '');
            if (! $this->safePath($disk, $path) || ! Storage::disk($disk)->exists($path) || Storage::disk($disk)->size($path) < 1) {
                throw new StoryException('Some video parts are not ready yet.', self::NOT_READY, 422);
            }
            $segments[] = new StoryMediaSegment(
                disk: $disk,
                path: $path,
                expectedSeconds: (float) ($unit['duration_seconds'] ?? 0),
                toleranceSeconds: StoryProductionUnitGenerationService::DURATION_TOLERANCE_SECONDS,
                expectedAspect: $aspect,
            );
        }

        $output = 'assemblies/'.$assembly->uuid.'.mp4';
        $file = $this->media->compose($segments, [], $output, $aspect, (float) $expected);
        $tolerance = StoryProductionUnitGenerationService::DURATION_TOLERANCE_SECONDS;
        $shape = $this->media->frameSize($aspect);
        if (
            abs($file->durationSeconds - $expected) > $tolerance
            || ! $file->hasVideo
            || $file->size < 1
            || [$file->width, $file->height] !== $shape
        ) {
            $this->discard($assembly, $output);
            throw new StoryException('The finished scene video does not match the planned length.', 'SCENE_ASSEMBLY_DURATION', 422);
        }

        $number = $this->assignVersion($assembly);
        $assembly->forceFill([
            'status' => StoryVideoJobStatus::Completed->value,
            'version_number' => $number,
            'disk' => $file->disk,
            'path' => $file->path,
            'mime' => $file->mime,
            'size_bytes' => $file->size,
            'duration_seconds' => round($file->durationSeconds, 2),
            'error_code' => null,
            'error_message' => null,
            'completed_at' => now(),
        ])->save();
        $fresh = $assembly->fresh() ?? $assembly;
        $this->log($fresh, $fresh->requester, self::EVENT_COMPLETED, 'Scene video is ready');
        $this->log($fresh, $fresh->requester, self::EVENT_VERSION, 'Scene video version created');
    }

    private function assignVersion(StorySceneAssembly $assembly): int
    {
        return (int) DB::transaction(function () use ($assembly): int {
            $scene = StoryProductionPlanScene::query()->whereKey($assembly->story_production_plan_scene_id)->lockForUpdate()->first();
            $next = (int) StorySceneAssembly::query()
                ->where('story_production_plan_scene_id', $scene?->id)
                ->max('version_number') + 1;
            if ((int) $scene?->duration_seconds !== (int) $assembly->expected_duration_seconds) {
                throw new StoryException('The finished scene video does not match the planned length.', 'SCENE_ASSEMBLY_DURATION', 422);
            }

            return $next;
        });
    }

    private function fail(StorySceneAssembly $assembly, Throwable $exception): void
    {
        $this->discard($assembly);
        $code = $exception instanceof StoryException ? $exception->errorCode() : 'SCENE_ASSEMBLY_FAILED';
        $message = $exception instanceof StoryException
            ? $exception->getMessage()
            : 'The scene video could not be built.';
        $assembly->forceFill([
            'status' => StoryVideoJobStatus::Failed->value,
            'disk' => null,
            'path' => null,
            'mime' => null,
            'size_bytes' => null,
            'duration_seconds' => null,
            'error_code' => $code,
            'error_message' => $message,
            'failed_at' => now(),
        ])->save();
        $this->log($assembly, $assembly->requester, self::EVENT_FAILED, 'Scene assembly failed');
        if ($exception instanceof StoryException) {
            throw $exception;
        }

        throw new StoryException($message, 'SCENE_ASSEMBLY_FAILED', 422);
    }

    /**
     * @return array{key: string, ready: bool, aspect: string, sources: list<array<string, mixed>>}
     */
    private function collect(StoryProductionPlanScene $scene): array
    {
        $scene->loadMissing(['plan.workspace.bible', 'units.selectedVersion']);
        $aspect = $this->aspect($scene);
        $units = $scene->units->sortBy(static fn (StoryProductionUnit $unit): string => sprintf('%08d-%08d', $unit->sequence, $unit->id))->values();
        $sources = [];
        $tokens = [];
        $ready = $units->isNotEmpty();
        foreach ($units as $unit) {
            if (! $unit instanceof StoryProductionUnit) {
                $ready = false;

                continue;
            }
            $source = $this->versions->assemblySource($unit);
            if ($source === null || ! $this->safePath((string) $source['disk'], (string) $source['path'])) {
                $ready = false;
                $tokens[] = 'missing:'.$unit->uuid;

                continue;
            }
            $tokens[] = (string) $source['version_id'];
            $sources[] = $source + ['unit_id' => $unit->uuid];
        }
        $sequences = array_map(static fn (array $source): int => (int) $source['sequence'], $sources);
        if ($ready && $sequences !== range(1, count($sources))) {
            $ready = false;
        }

        return [
            'key' => 'scene-assembly:'.$scene->uuid.':'.hash('sha256', implode('|', $tokens).'|'.$aspect.'|cut'),
            'ready' => $ready,
            'aspect' => $aspect,
            'sources' => $sources,
        ];
    }

    /**
     * @param  array{key: string, ready: bool, aspect: string, sources: list<array<string, mixed>>}  $built
     * @return array<string, mixed>
     */
    private function snapshot(StoryProductionPlanScene $scene, array $built): array
    {
        $scene->loadMissing(['plan', 'scene']);

        return [
            'scene_id' => $scene->scene?->uuid,
            'plan_id' => $scene->plan->uuid,
            'plan_revision' => $scene->plan->revision,
            'duration_seconds' => (int) $scene->duration_seconds,
            'aspect_ratio' => $built['aspect'],
            'transition' => 'cut',
            'units' => array_map(static function (array $source): array {
                return [
                    'unit_id' => $source['unit_id'] ?? null,
                    'sequence' => $source['sequence'] ?? null,
                    'start_second' => $source['start_second'] ?? null,
                    'duration_seconds' => $source['duration_seconds'] ?? null,
                    'version_id' => $source['version_id'] ?? null,
                    'disk' => $source['disk'] ?? null,
                    'path' => $source['path'] ?? null,
                    'output_duration_seconds' => $source['output_duration_seconds'] ?? null,
                ];
            }, $built['sources']),
        ];
    }

    private function aspect(StoryProductionPlanScene $scene): string
    {
        $ratio = $scene->plan->workspace?->bible?->aspect_ratio;
        $allowed = ['16:9', '9:16', '1:1', '4:3', '3:4', '21:9'];

        return is_string($ratio) && in_array($ratio, $allowed, true) ? $ratio : '16:9';
    }

    private function findIntent(StoryProductionPlanScene $scene, string $key): ?StorySceneAssembly
    {
        return StorySceneAssembly::query()
            ->where('story_workspace_id', $scene->plan->story_workspace_id)
            ->where('idempotency_key', $key)
            ->first();
    }

    private function running(StorySceneAssembly $assembly): bool
    {
        return in_array($assembly->statusEnum(), [StoryVideoJobStatus::Queued, StoryVideoJobStatus::Processing], true);
    }

    private function assignPresent(StorySceneAssembly $assembly): string
    {
        return $assembly->version_number === null ? '' : StoryAudioService::versionLabel((int) $assembly->version_number);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(StorySceneAssembly $assembly): array
    {
        $assembly->loadMissing('planScene.scene');
        $label = $this->assignPresent($assembly);
        $status = $assembly->statusEnum();

        return [
            'id' => $assembly->uuid,
            'scene_id' => $assembly->planScene?->scene?->uuid,
            'version' => $label === '' ? null : substr($label, 8),
            'label' => $label === '' ? null : $label,
            'status' => $status->value,
            'status_label' => match ($status) {
                StoryVideoJobStatus::Completed => 'Scene video is ready',
                StoryVideoJobStatus::Processing => 'Building the scene video',
                StoryVideoJobStatus::Queued => 'Waiting to build',
                default => 'The scene video could not be built',
            },
            'output_available' => $this->fileReady($assembly),
            'duration_seconds' => (int) $assembly->expected_duration_seconds,
            'output_duration_seconds' => $assembly->duration_seconds === null ? null : (float) $assembly->duration_seconds,
            'error_message' => $status === StoryVideoJobStatus::Failed ? $assembly->error_message : null,
            'created_at' => $assembly->created_at?->toIso8601String(),
            'updated_at' => $assembly->updated_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function presentCurrent(mixed $assembly): ?array
    {
        return $assembly instanceof StorySceneAssembly ? $this->present($assembly) : null;
    }

    private function fileReady(StorySceneAssembly $assembly): bool
    {
        $path = (string) $assembly->path;

        return $assembly->isComplete()
            && $assembly->disk === 'videos'
            && $this->safePath('videos', $path)
            && Storage::disk('videos')->exists($path)
            && Storage::disk('videos')->size($path) > 0;
    }

    private function safePath(string $disk, string $path): bool
    {
        return $disk === 'videos'
            && $path !== ''
            && ! str_contains($path, '..')
            && ! str_contains($path, '://')
            && ! preg_match('/[;&|`$<>]/', $path);
    }

    private function discard(StorySceneAssembly $assembly, ?string $path = null): void
    {
        $path ??= $assembly->path ?? 'assemblies/'.$assembly->uuid.'.mp4';
        if (is_string($path) && $this->safePath('videos', $path) && Storage::disk('videos')->exists($path)) {
            Storage::disk('videos')->delete($path);
        }
    }

    private function sweepScratch(\DateTimeInterface $cutoff): void
    {
        $root = storage_path('app/story-media');
        if (! is_dir($root)) {
            return;
        }
        foreach (File::directories($root) as $directory) {
            if (filemtime($directory) !== false && filemtime($directory) < $cutoff->getTimestamp()) {
                File::deleteDirectory($directory);
            }
        }
    }

    private function scene(Project $project, string $planUuid, string $sceneUuid): StoryProductionPlanScene
    {
        return $this->plans->sceneForPlan($project, $planUuid, $sceneUuid);
    }

    private function owned(Project $project, string $planUuid, string $sceneUuid, string $assemblyUuid): StorySceneAssembly
    {
        $scene = $this->scene($project, $planUuid, $sceneUuid);
        $assembly = StorySceneAssembly::query()
            ->where('uuid', $assemblyUuid)
            ->where('story_production_plan_scene_id', $scene->id)
            ->first();
        if (! $assembly instanceof StorySceneAssembly) {
            throw StoryException::notFound('Scene video');
        }

        return $assembly;
    }

    private function log(StorySceneAssembly $assembly, ?User $actor, string $action, string $description): void
    {
        $assembly->loadMissing('planScene.scene');
        $this->activity->log($action, $assembly, $actor, $description, [
            'version' => $this->assignPresent($assembly) ?: null,
            'scene_sequence' => $assembly->planScene?->scene?->sequence,
        ]);
    }
}
