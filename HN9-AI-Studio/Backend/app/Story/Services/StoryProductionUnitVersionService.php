<?php

declare(strict_types=1);

namespace App\Story\Services;

use App\Contracts\Logging\ActivityLoggerInterface;
use App\Models\Project;
use App\Models\User;
use App\Story\Contracts\StoryProductionPlanServiceInterface;
use App\Story\Contracts\StoryProductionUnitVersionServiceInterface;
use App\Story\Enums\StoryReviewStatus;
use App\Story\Enums\StoryVideoJobStatus;
use App\Story\Exceptions\StoryException;
use App\Story\Exceptions\StoryReviewException;
use App\Story\Exceptions\StoryVideoEngineException;
use App\Story\Models\StoryProductionPlan;
use App\Story\Models\StoryProductionUnit;
use App\Story\Models\StoryProductionUnitVersion;
use App\Story\Models\StoryScene;
use App\Story\Models\StoryVideoGenerationJob;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Successful generation attempts become Unit Versions. The unit is never replaced,
 * old versions are never overwritten, and only an approved version can be selected.
 */
final readonly class StoryProductionUnitVersionService implements StoryProductionUnitVersionServiceInterface
{
    public const EVENT_CREATED = 'story.unit_version.created';

    public const EVENT_READY = 'story.unit_version.ready';

    public const EVENT_APPROVED = 'story.unit_version.approved';

    public const EVENT_CHANGES_REQUESTED = 'story.unit_version.changes_requested';

    public const EVENT_SELECTED = 'story.unit_version.selected';

    public const EVENT_DESELECTED = 'story.unit_version.deselected';

    public function __construct(
        private StoryProductionPlanServiceInterface $plans,
        private ActivityLoggerInterface $activity,
    ) {}

    public function recordAccepted(
        StoryVideoGenerationJob $job,
        StoryProductionUnit $unit,
        float $producedSeconds,
        string $disk,
        string $path,
        string $mime,
    ): StoryProductionUnitVersion {
        $existing = $this->forJob($job);
        if ($existing instanceof StoryProductionUnitVersion) {
            return $existing;
        }

        try {
            return $this->insertVersion($job, $unit, $producedSeconds, $disk, $path, $mime);
        } catch (UniqueConstraintViolationException) {
            $existing = $this->forJob($job);
            if ($existing instanceof StoryProductionUnitVersion) {
                return $existing;
            }

            return $this->insertVersion($job, $unit, $producedSeconds, $disk, $path, $mime);
        }
    }

    public function listForUnit(Project $project, string $planUuid, string $unitUuid): array
    {
        [, $unit] = $this->owned($project, $planUuid, $unitUuid);
        $versions = $this->versions($unit);

        return [
            'message' => $versions->isEmpty() ? $this->emptyMessage($unit) : null,
            'versions' => $versions->map(fn (StoryProductionUnitVersion $version): array => $this->present($version, $unit))->values()->all(),
        ];
    }

    public function show(Project $project, string $planUuid, string $unitUuid, string $versionUuid): array
    {
        [, $unit, $version] = $this->ownedVersion($project, $planUuid, $unitUuid, $versionUuid);

        return $this->present($version, $unit);
    }

    public function approve(Project $project, string $planUuid, string $unitUuid, string $versionUuid, User $actor, ?string $comment = null): array
    {
        [, $unit, $version] = $this->ownedVersion($project, $planUuid, $unitUuid, $versionUuid);
        $changed = false;

        DB::transaction(function () use ($version, $actor, $comment, &$changed): void {
            $locked = $this->lockVersion($version);
            if ($locked->isApproved()) {
                return;
            }
            if ($locked->reviewStatusEnum() !== StoryReviewStatus::PendingReview || ! $this->fileReady($locked)) {
                throw StoryReviewException::unit('This video is not ready for review.');
            }
            $locked->forceFill([
                'review_status' => StoryReviewStatus::Approved->value,
                'review_comment' => $this->comment($comment) ?? $locked->review_comment,
                'reviewed_at' => now(),
                'reviewed_by' => $actor->id,
            ])->save();
            $changed = true;
        });

        $fresh = $version->fresh() ?? $version;
        if ($changed) {
            $this->log($fresh, $unit, $actor, self::EVENT_APPROVED, 'Version approved');
        }

        return ['version' => $this->present($fresh, $unit->fresh() ?? $unit), 'changed' => $changed];
    }

    public function requestChanges(Project $project, string $planUuid, string $unitUuid, string $versionUuid, User $actor, string $comment): array
    {
        $comment = $this->comment($comment);
        if ($comment === null) {
            throw StoryVideoEngineException::invalidInput('Say what should change.');
        }
        [, $unit, $version] = $this->ownedVersion($project, $planUuid, $unitUuid, $versionUuid);
        $changed = false;

        DB::transaction(function () use ($version, $actor, $comment, &$changed): void {
            $locked = $this->lockVersion($version);
            if ($locked->reviewStatusEnum() === StoryReviewStatus::NeedsRework) {
                return;
            }
            if ($locked->isApproved()) {
                throw StoryReviewException::unit('This video is already approved. Generate a new version to change it.');
            }
            if ($locked->reviewStatusEnum() !== StoryReviewStatus::PendingReview || ! $this->fileReady($locked)) {
                throw StoryReviewException::unit('This video is not ready for review.');
            }
            $locked->forceFill([
                'review_status' => StoryReviewStatus::NeedsRework->value,
                'review_comment' => $comment,
                'reviewed_at' => now(),
                'reviewed_by' => $actor->id,
            ])->save();
            $changed = true;
        });

        $fresh = $version->fresh() ?? $version;
        if ($changed) {
            $this->log($fresh, $unit, $actor, self::EVENT_CHANGES_REQUESTED, 'Changes requested', ['comment' => $comment]);
        }

        return ['version' => $this->present($fresh, $unit), 'changed' => $changed];
    }

    public function select(Project $project, string $planUuid, string $unitUuid, string $versionUuid, User $actor): array
    {
        [, $unit, $version] = $this->ownedVersion($project, $planUuid, $unitUuid, $versionUuid);
        if (! $version->isApproved() || ! $this->fileReady($version)) {
            throw StoryReviewException::unit('Only an approved video can be used in the final scene.');
        }

        $previousId = null;
        $changed = false;
        DB::transaction(function () use ($unit, $version, &$previousId, &$changed): void {
            $lockedUnit = StoryProductionUnit::query()->whereKey($unit->id)->lockForUpdate()->first();
            $locked = $this->lockVersion($version);
            if (! $locked->isApproved() || ! $this->fileReady($locked) || (int) $locked->story_production_unit_id !== (int) $lockedUnit?->id) {
                throw StoryReviewException::unit('Only an approved video can be used in the final scene.');
            }
            if ((int) $lockedUnit->selected_version_id === (int) $locked->id) {
                return;
            }
            $previousId = $lockedUnit->selected_version_id;
            $lockedUnit->forceFill(['selected_version_id' => $locked->id])->save();
            $changed = true;
        });

        $freshUnit = $unit->fresh() ?? $unit;
        if ($changed && $previousId !== null) {
            $previous = StoryProductionUnitVersion::query()->find($previousId);
            if ($previous instanceof StoryProductionUnitVersion) {
                $this->log($previous, $freshUnit, $actor, self::EVENT_DESELECTED, 'Version no longer selected');
            }
        }
        $fresh = $version->fresh() ?? $version;
        if ($changed) {
            $this->log($fresh, $freshUnit, $actor, self::EVENT_SELECTED, 'Version selected for the final scene');
        }

        return ['version' => $this->present($fresh, $freshUnit), 'changed' => $changed];
    }

    public function file(Project $project, string $planUuid, string $unitUuid, string $versionUuid): StreamedResponse
    {
        [, , $version] = $this->ownedVersion($project, $planUuid, $unitUuid, $versionUuid);
        if (! $this->fileReady($version)) {
            throw StoryVideoEngineException::invalidInput('This video is no longer available.');
        }

        return Storage::disk('videos')->response($version->path, basename($version->path), [
            'Content-Type' => is_string($version->mime) && $version->mime !== '' ? $version->mime : 'video/mp4',
        ]);
    }

    public function assemblySource(StoryProductionUnit $unit): ?array
    {
        $version = $unit->relationLoaded('selectedVersion') ? $unit->selectedVersion : $unit->selectedVersion()->first();
        if (! $version instanceof StoryProductionUnitVersion || ! $version->isApproved() || ! $this->fileReady($version)) {
            return null;
        }

        return [
            'version_id' => $version->uuid,
            'sequence' => $unit->sequence,
            'start_second' => $unit->start_second,
            'duration_seconds' => $unit->duration_seconds,
            'output_duration_seconds' => (float) $version->produced_duration_seconds,
            'disk' => $version->disk,
            'path' => $version->path,
            'approved' => true,
            'selected' => true,
        ];
    }

    public function continuityOutput(StoryProductionUnit $unit): array
    {
        $source = $this->assemblySource($unit);

        return [
            'available' => $source !== null,
            'id' => $unit->uuid,
            'sequence' => $unit->sequence,
            'version_id' => $source['version_id'] ?? null,
            'output' => $source === null ? null : ['disk' => $source['disk'], 'path' => $source['path']],
        ];
    }

    private function insertVersion(
        StoryVideoGenerationJob $job,
        StoryProductionUnit $unit,
        float $producedSeconds,
        string $disk,
        string $path,
        string $mime,
    ): StoryProductionUnitVersion {
        return DB::transaction(function () use ($job, $unit, $producedSeconds, $disk, $path, $mime): StoryProductionUnitVersion {
            $lockedUnit = StoryProductionUnit::query()->whereKey($unit->id)->lockForUpdate()->first();
            if (! $lockedUnit instanceof StoryProductionUnit || (int) $job->story_production_unit_id !== (int) $lockedUnit->id) {
                throw StoryVideoEngineException::invalidInput('This generation does not belong to the unit.');
            }
            $existing = $this->forJob($job);
            if ($existing instanceof StoryProductionUnitVersion) {
                return $existing;
            }

            $duration = (int) $lockedUnit->duration_seconds;
            $start = (int) $lockedUnit->start_second;
            $next = (int) StoryProductionUnitVersion::query()
                ->where('story_production_unit_id', $lockedUnit->id)
                ->max('version_number') + 1;
            $size = Storage::disk('videos')->exists($path) ? Storage::disk('videos')->size($path) : null;
            $routing = is_array($job->routing) ? $job->routing : [];
            $context = $job->request_payload['metadata']['context'] ?? null;

            $version = new StoryProductionUnitVersion;
            $version->forceFill([
                'story_production_unit_id' => $lockedUnit->id,
                'story_video_generation_job_id' => $job->id,
                'version_number' => $next,
                'review_status' => StoryReviewStatus::PendingReview->value,
                'provider_key' => $job->provider_key,
                'model_key' => $job->model_key,
                'capability' => $job->capability,
                'requested_duration_seconds' => $duration,
                'produced_duration_seconds' => round($producedSeconds, 2),
                'disk' => $disk,
                'path' => $path,
                'mime' => $mime,
                'size' => $size,
                'snapshot' => [
                    'provider_key' => $job->provider_key,
                    'model_key' => $job->model_key,
                    'capability' => $job->capability,
                    'requested_duration_seconds' => $duration,
                    'produced_duration_seconds' => round($producedSeconds, 2),
                    'routing' => [
                        'policy_version' => $routing['policy_version'] ?? null,
                        'reason' => $routing['reason'] ?? null,
                        'selected_provider' => $routing['selected_provider'] ?? $job->provider_key,
                        'selected_model' => $routing['selected_model'] ?? $job->model_key,
                    ],
                    'context' => is_array($context) ? $context : null,
                ],
            ])->save();

            if ((int) $lockedUnit->duration_seconds !== $duration || (int) $lockedUnit->start_second !== $start) {
                throw StoryVideoEngineException::invalidInput('The generation unit changed while its version was being saved.');
            }

            $this->log($version, $lockedUnit, null, self::EVENT_CREATED, 'Version created');
            $this->log($version, $lockedUnit, null, self::EVENT_READY, 'Version ready for review');

            return $version;
        });
    }

    private function forJob(StoryVideoGenerationJob $job): ?StoryProductionUnitVersion
    {
        return StoryProductionUnitVersion::query()
            ->where('story_video_generation_job_id', $job->id)
            ->first();
    }

    private function lockVersion(StoryProductionUnitVersion $version): StoryProductionUnitVersion
    {
        $locked = StoryProductionUnitVersion::query()->whereKey($version->id)->lockForUpdate()->first();
        if (! $locked instanceof StoryProductionUnitVersion) {
            throw StoryReviewException::unit('This video version could not be found.');
        }

        return $locked;
    }

    /**
     * @return Collection<int, StoryProductionUnitVersion>
     */
    private function versions(StoryProductionUnit $unit): Collection
    {
        return StoryProductionUnitVersion::query()
            ->where('story_production_unit_id', $unit->id)
            ->orderBy('version_number')
            ->orderBy('id')
            ->get();
    }

    private function emptyMessage(StoryProductionUnit $unit): string
    {
        $latest = StoryVideoGenerationJob::query()
            ->where('story_production_unit_id', $unit->id)
            ->orderByDesc('id')
            ->first();
        if ($latest instanceof StoryVideoGenerationJob && in_array($latest->statusEnum(), [StoryVideoJobStatus::Queued, StoryVideoJobStatus::Submitted, StoryVideoJobStatus::Processing], true)) {
            return 'Your video is still being generated.';
        }
        if ($latest instanceof StoryVideoGenerationJob && $latest->statusEnum() === StoryVideoJobStatus::Failed) {
            return 'No video version was created. The generation failed.';
        }

        return 'No video versions yet.';
    }

    /**
     * @return array{0: StoryProductionPlan, 1: StoryProductionUnit, 2: StoryScene}
     */
    private function owned(Project $project, string $planUuid, string $unitUuid): array
    {
        $plan = $this->plans->getForProject($project, $planUuid);
        $unit = StoryProductionUnit::query()
            ->where('uuid', $unitUuid)
            ->whereHas('planScene', static function ($query) use ($plan): void {
                $query->where('story_production_plan_id', $plan->id);
            })
            ->first();
        if (! $unit instanceof StoryProductionUnit) {
            throw StoryException::notFound('Generation unit');
        }
        $scene = $unit->planScene()->first()?->scene;
        if (! $scene instanceof StoryScene) {
            throw StoryException::notFound('Generation unit');
        }

        return [$plan, $unit, $scene];
    }

    /**
     * @return array{0: StoryProductionPlan, 1: StoryProductionUnit, 2: StoryProductionUnitVersion}
     */
    private function ownedVersion(Project $project, string $planUuid, string $unitUuid, string $versionUuid): array
    {
        [$plan, $unit] = $this->owned($project, $planUuid, $unitUuid);
        $version = StoryProductionUnitVersion::query()
            ->where('uuid', $versionUuid)
            ->where('story_production_unit_id', $unit->id)
            ->first();
        if (! $version instanceof StoryProductionUnitVersion) {
            throw StoryException::notFound('Video version');
        }

        return [$plan, $unit, $version];
    }

    private function fileReady(StoryProductionUnitVersion $version): bool
    {
        $path = (string) $version->path;
        if ($version->disk !== 'videos' || $path === '' || str_contains($path, '..') || str_contains($path, '://')) {
            return false;
        }

        return Storage::disk('videos')->exists($path) && Storage::disk('videos')->size($path) > 0;
    }

    /**
     * @return array<string, mixed>
     */
    private function present(StoryProductionUnitVersion $version, StoryProductionUnit $unit): array
    {
        $selected = (int) $unit->selected_version_id === (int) $version->id;
        $status = $version->reviewStatusEnum();

        return [
            'id' => $version->uuid,
            'unit_id' => $unit->uuid,
            'version' => $this->letter((int) $version->version_number),
            'label' => StoryAudioService::versionLabel((int) $version->version_number),
            'status' => $status->value,
            'status_label' => match ($status) {
                StoryReviewStatus::Approved => 'Approved',
                StoryReviewStatus::NeedsRework => 'Changes requested',
                default => 'Ready for review',
            },
            'selected' => $selected,
            'selected_label' => $selected ? 'Selected for the final scene' : null,
            'approved' => $version->isApproved(),
            'duration_seconds' => $unit->duration_seconds,
            'output_duration_seconds' => (float) $version->produced_duration_seconds,
            'preview_available' => $this->fileReady($version),
            'provider' => $version->provider_key,
            'model' => $version->model_key,
            'comment' => $version->review_comment,
            'created_at' => $version->created_at?->toIso8601String(),
            'updated_at' => $version->updated_at?->toIso8601String(),
        ];
    }

    private function letter(int $number): string
    {
        $label = StoryAudioService::versionLabel($number);

        return str_starts_with($label, 'Version ') ? substr($label, 8) : $label;
    }

    private function comment(mixed $comment): ?string
    {
        if (! is_string($comment)) {
            return null;
        }
        $comment = trim($comment);

        return $comment === '' ? null : mb_substr($comment, 0, 2000);
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function log(StoryProductionUnitVersion $version, StoryProductionUnit $unit, ?User $actor, string $action, string $description, array $extra = []): void
    {
        $this->activity->log($action, $version, $actor, $description, [
            'version' => StoryAudioService::versionLabel((int) $version->version_number),
            'unit_sequence' => $unit->sequence,
        ] + $extra);
    }
}
