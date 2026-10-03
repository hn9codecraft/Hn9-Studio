<?php

declare(strict_types=1);

namespace App\Story\Services;

use App\AI\Support\ProviderErrorSanitizer;
use App\Enums\CostSource;
use App\Models\ActivityLog;
use App\Models\Project;
use App\Story\Enums\StoryVideoCapability;
use App\Story\Enums\StoryVideoJobStatus;
use App\Story\Models\StoryFinalRender;
use App\Story\Models\StoryGenerationAttempt;
use App\Story\Models\StoryPlan;
use App\Story\Models\StoryPlanVersion;
use App\Story\Models\StoryProductionPlan;
use App\Story\Models\StoryProductionUnitVersion;
use App\Story\Models\StorySceneAudio;
use App\Story\Models\StoryUsageLedgerEntry;
use App\Story\Models\StoryVideoGenerationJob;
use Illuminate\Support\Collection;

/**
 * Story generation history and usage ledger. Reads stored jobs only and never calls a provider.
 * A cost is recorded only when the provider response stored provider_metadata.reported_cost.
 */
final class StoryUsageService
{
    private const TERMINAL = [
        StoryVideoJobStatus::Completed,
        StoryVideoJobStatus::Failed,
        StoryVideoJobStatus::Cancelled,
    ];

    private const UNIT_VERSION_EVENTS = [
        StoryProductionUnitVersionService::EVENT_CREATED => 'version_created',
        StoryProductionUnitVersionService::EVENT_READY => 'ready_for_review',
        StoryProductionUnitVersionService::EVENT_APPROVED => 'approved',
        StoryProductionUnitVersionService::EVENT_CHANGES_REQUESTED => 'changes_requested',
        StoryProductionUnitVersionService::EVENT_SELECTED => 'selected',
        StoryProductionUnitVersionService::EVENT_DESELECTED => 'deselected',
    ];

    private const SOUND_EVENTS = [
        StoryAudioService::EVENT_VERSION_CREATED => 'version_created',
        StoryAudioService::EVENT_REWORKED => 'reworked',
        StoryAudioService::EVENT_APPROVED => 'approved',
        StoryAudioService::EVENT_CHANGES_REQUESTED => 'changes_requested',
        StoryAudioService::EVENT_SELECTED => 'selected',
    ];

    private const STORY_EVENTS = [
        StoryPlannerService::EVENT_VERSION_READY => 'ready_for_review',
        StoryPlanApprovalService::EVENT_APPROVED => 'approved',
        StoryPlanApprovalService::EVENT_APPROVAL_FAILED => 'approval_failed',
    ];

    private const PRODUCTION_PLAN_EVENTS = [
        StoryProductionPlanService::EVENT_CREATED => 'created',
        StoryProductionPlanService::EVENT_REVISED => 'revised',
        StoryPlanApprovalService::EVENT_PLAN_REUSED => 'reused',
    ];

    public function recordTerminal(StoryVideoGenerationJob $job): void
    {
        if (! in_array($job->statusEnum(), self::TERMINAL, true)) {
            return;
        }

        $cost = $this->reportedCost($job);
        StoryUsageLedgerEntry::query()->firstOrCreate(
            ['story_video_generation_job_id' => $job->id],
            [
                'story_workspace_id' => $job->story_workspace_id,
                'capability' => $job->capability,
                'provider_key' => $job->provider_key,
                'model_key' => $job->model_key,
                'operation_id' => $job->operation_id,
                'status' => $job->status,
                'cost' => $cost['amount'],
                'currency' => $cost['currency'],
                'cost_source' => $cost['amount'] === null ? null : CostSource::ProviderReported->value,
                'recorded_at' => now(),
            ],
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function history(Project $project): array
    {
        $jobs = StoryVideoGenerationJob::query()
            ->with(['reel', 'scene', 'productionUnit'])
            ->whereHas('workspace', static function ($query) use ($project): void {
                $query->where('project_id', $project->id);
            })
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();
        $ledger = StoryUsageLedgerEntry::query()
            ->whereIn('story_video_generation_job_id', $jobs->pluck('id'))
            ->get()
            ->keyBy('story_video_generation_job_id');

        $items = $jobs->map(function (StoryVideoGenerationJob $job) use ($ledger): array {
            $entry = $ledger->get($job->id);

            return [
                'id' => $job->uuid,
                'kind' => $job->story_production_unit_id !== null
                    ? 'unit_generation'
                    : ($job->capability === StoryVideoCapability::Audio->value ? 'audio' : 'video'),
                'version_label' => $job->productionUnit === null ? null : 'Unit '.$job->productionUnit->sequence,
                'reel_title' => $job->reel?->title,
                'scene_sequence' => $job->scene?->sequence,
                'scene_title' => $job->scene?->title,
                'reel_id' => $job->reel?->uuid,
                'scene_id' => $job->scene?->uuid,
                'capability' => $job->capability,
                'provider_key' => $job->provider_key,
                'model_key' => $job->model_key,
                'operation_id' => $job->operation_id,
                'status' => $job->status,
                'error_code' => $job->error_code,
                'error_message' => $job->error_message === null
                    ? null
                    : mb_substr(ProviderErrorSanitizer::message($job->error_message, 'The provider request failed.'), 0, 300),
                'created_at' => $job->created_at?->toIso8601String(),
                'submitted_at' => $job->submitted_at?->toIso8601String(),
                'started_at' => $job->started_at?->toIso8601String(),
                'completed_at' => $job->completed_at?->toIso8601String(),
                'failed_at' => $job->failed_at?->toIso8601String(),
                'cost' => $entry?->cost,
                'currency' => $entry?->currency,
                'cost_source' => $entry?->cost_source,
                'cost_reported' => $entry instanceof StoryUsageLedgerEntry && $entry->cost !== null,
                'ledger_recorded_at' => $entry?->recorded_at?->toIso8601String(),
            ];
        });

        // Sound events go first so a version is listed before its generation job when both share a second.
        return $this->storyEvents($project)
            ->concat($this->soundEvents($project))
            ->concat($this->unitVersionEvents($project))
            ->concat($items)
            ->concat($this->attempts($project))
            ->concat($this->renders($project))
            ->sortBy(static fn (array $item): string => (string) $item['created_at'])
            ->values()
            ->all();
    }

    /**
     * Attempts that never became a job, shaped like job rows.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function attempts(Project $project): Collection
    {
        return StoryGenerationAttempt::query()
            ->with(['reel', 'scene'])
            ->whereHas('workspace', static function ($query) use ($project): void {
                $query->where('project_id', $project->id);
            })
            ->orderBy('created_at')
            ->orderBy('id')
            ->get()
            ->map(static fn (StoryGenerationAttempt $attempt): array => [
                'id' => $attempt->uuid,
                'kind' => $attempt->kind,
                'reel_title' => $attempt->reel?->title,
                'scene_sequence' => $attempt->scene?->sequence,
                'scene_title' => $attempt->scene?->title,
                'reel_id' => $attempt->reel?->uuid,
                'scene_id' => $attempt->scene?->uuid,
                'capability' => $attempt->capability,
                'provider_key' => null,
                'model_key' => null,
                'operation_id' => null,
                'status' => $attempt->status,
                'error_code' => $attempt->error_code,
                'error_message' => $attempt->error_message,
                'created_at' => $attempt->created_at?->toIso8601String(),
                'submitted_at' => null,
                'started_at' => null,
                'completed_at' => null,
                'failed_at' => $attempt->created_at?->toIso8601String(),
                'cost' => null,
                'currency' => null,
                'cost_source' => null,
                'cost_reported' => false,
                'ledger_recorded_at' => null,
            ]);
    }

    /**
     * Final video builds. Rendering runs locally, so no provider or cost applies.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function renders(Project $project): Collection
    {
        return StoryFinalRender::query()
            ->with('reel')
            ->whereHas('reel.workspace', static function ($query) use ($project): void {
                $query->where('project_id', $project->id);
            })
            ->orderBy('created_at')
            ->orderBy('id')
            ->get()
            ->map(static fn (StoryFinalRender $render): array => [
                'id' => $render->uuid,
                'kind' => 'final_video',
                'reel_title' => $render->reel?->title,
                'scene_sequence' => null,
                'scene_title' => null,
                'reel_id' => $render->reel?->uuid,
                'scene_id' => null,
                'capability' => null,
                'provider_key' => null,
                'model_key' => null,
                'operation_id' => null,
                'status' => $render->status,
                'error_code' => $render->error_code,
                'error_message' => null,
                'created_at' => $render->created_at?->toIso8601String(),
                'submitted_at' => null,
                'started_at' => null,
                'completed_at' => $render->status === StoryVideoJobStatus::Completed->value
                    ? $render->updated_at?->toIso8601String()
                    : null,
                'failed_at' => $render->status === StoryVideoJobStatus::Failed->value
                    ? $render->updated_at?->toIso8601String()
                    : null,
                'cost' => null,
                'currency' => null,
                'cost_source' => null,
                'cost_reported' => false,
                'ledger_recorded_at' => null,
            ]);
    }

    /**
     * Sound version and review steps from the activity log, shaped like job rows.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function soundEvents(Project $project): Collection
    {
        $audios = StorySceneAudio::query()
            ->with('scene.reel')
            ->whereHas('workspace', static function ($query) use ($project): void {
                $query->where('project_id', $project->id);
            })
            ->get()
            ->keyBy('id');
        if ($audios->isEmpty()) {
            return collect();
        }

        return ActivityLog::query()
            ->where('subject_type', (new StorySceneAudio)->getMorphClass())
            ->whereIn('subject_id', $audios->keys())
            ->whereIn('action', array_keys(self::SOUND_EVENTS))
            ->orderBy('created_at')
            ->orderBy('id')
            ->get()
            ->map(static function (ActivityLog $log) use ($audios): array {
                $audio = $audios->get($log->subject_id);
                $properties = is_array($log->properties) ? $log->properties : [];

                return [
                    'id' => $log->uuid,
                    'kind' => 'sound_review',
                    'event' => self::SOUND_EVENTS[$log->action],
                    'version_label' => is_string($properties['version'] ?? null) ? $properties['version'] : null,
                    'role' => $audio?->role,
                    'comment' => is_string($properties['comment'] ?? null) ? $properties['comment'] : null,
                    'reel_title' => $audio?->scene?->reel?->title,
                    'scene_sequence' => $audio?->scene?->sequence,
                    'scene_title' => $audio?->scene?->title,
                    'reel_id' => $audio?->scene?->reel?->uuid,
                    'scene_id' => $audio?->scene?->uuid,
                    'capability' => StoryVideoCapability::Audio->value,
                    'provider_key' => null,
                    'model_key' => null,
                    'operation_id' => null,
                    'status' => self::SOUND_EVENTS[$log->action],
                    'error_code' => null,
                    'error_message' => null,
                    'created_at' => $log->created_at?->toIso8601String(),
                    'submitted_at' => null,
                    'started_at' => null,
                    'completed_at' => null,
                    'failed_at' => null,
                    'cost' => null,
                    'currency' => null,
                    'cost_source' => null,
                    'cost_reported' => false,
                    'ledger_recorded_at' => null,
                ];
            });
    }

    /**
     * Unit version review steps. Descriptions stay in the activity log; this list uses plain labels.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function unitVersionEvents(Project $project): Collection
    {
        $versions = StoryProductionUnitVersion::query()
            ->with(['unit.planScene.scene.reel'])
            ->whereHas('unit.planScene.plan.workspace', static function ($query) use ($project): void {
                $query->where('project_id', $project->id);
            })
            ->get()
            ->keyBy('id');
        if ($versions->isEmpty()) {
            return collect();
        }

        return ActivityLog::query()
            ->where('subject_type', (new StoryProductionUnitVersion)->getMorphClass())
            ->whereIn('subject_id', $versions->keys())
            ->whereIn('action', array_keys(self::UNIT_VERSION_EVENTS))
            ->orderBy('created_at')
            ->orderBy('id')
            ->get()
            ->map(static function (ActivityLog $log) use ($versions): array {
                $version = $versions->get($log->subject_id);
                $properties = is_array($log->properties) ? $log->properties : [];
                $scene = $version?->unit?->planScene?->scene;

                return [
                    'id' => $log->uuid,
                    'kind' => 'unit_version',
                    'event' => self::UNIT_VERSION_EVENTS[$log->action],
                    'version_label' => is_string($properties['version'] ?? null) ? $properties['version'] : null,
                    'role' => null,
                    'comment' => is_string($properties['comment'] ?? null) ? $properties['comment'] : null,
                    'reel_title' => $scene?->reel?->title,
                    'scene_sequence' => $scene?->sequence,
                    'scene_title' => $scene?->title,
                    'reel_id' => $scene?->reel?->uuid,
                    'scene_id' => $scene?->uuid,
                    'capability' => null,
                    'provider_key' => null,
                    'model_key' => null,
                    'operation_id' => null,
                    'status' => self::UNIT_VERSION_EVENTS[$log->action],
                    'error_code' => null,
                    'error_message' => null,
                    'created_at' => $log->created_at?->toIso8601String(),
                    'submitted_at' => null,
                    'started_at' => null,
                    'completed_at' => null,
                    'failed_at' => null,
                    'cost' => null,
                    'currency' => null,
                    'cost_source' => null,
                    'cost_reported' => false,
                    'ledger_recorded_at' => null,
                ];
            });
    }

    /**
     * Story approval and production plan steps from the activity log, shaped like job rows.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function storyEvents(Project $project): Collection
    {
        $versions = StoryPlanVersion::query()
            ->whereHas('plan.workspace', static function ($query) use ($project): void {
                $query->where('project_id', $project->id);
            })
            ->get(['id', 'story_plan_id', 'version'])
            ->keyBy('id');
        if ($versions->isEmpty()) {
            return collect();
        }
        // The version's "plan" column shadows its plan() relation, so titles are read separately.
        $titles = StoryPlan::query()->whereIn('id', $versions->pluck('story_plan_id')->unique())->pluck('title', 'id');
        $productionPlans = StoryProductionPlan::query()
            ->whereIn('story_plan_version_id', $versions->keys())
            ->get(['id', 'story_plan_version_id', 'revision'])
            ->keyBy('id');

        return ActivityLog::query()
            ->where(static function ($query) use ($versions, $productionPlans): void {
                $query->where(static function ($inner) use ($versions): void {
                    $inner->where('subject_type', (new StoryPlanVersion)->getMorphClass())
                        ->whereIn('subject_id', $versions->keys())
                        ->whereIn('action', array_keys(self::STORY_EVENTS));
                });
                if ($productionPlans->isNotEmpty()) {
                    $query->orWhere(static function ($inner) use ($productionPlans): void {
                        $inner->where('subject_type', (new StoryProductionPlan)->getMorphClass())
                            ->whereIn('subject_id', $productionPlans->keys())
                            ->whereIn('action', array_keys(self::PRODUCTION_PLAN_EVENTS));
                    });
                }
            })
            ->orderBy('created_at')
            ->orderBy('id')
            ->get()
            ->map(static function (ActivityLog $log) use ($versions, $productionPlans, $titles): array {
                $isPlan = isset(self::PRODUCTION_PLAN_EVENTS[$log->action]);
                $productionPlan = $isPlan ? $productionPlans->get($log->subject_id) : null;
                $version = $versions->get($isPlan ? $productionPlan?->story_plan_version_id : $log->subject_id);
                $properties = is_array($log->properties) ? $log->properties : [];
                $event = $isPlan ? self::PRODUCTION_PLAN_EVENTS[$log->action] : self::STORY_EVENTS[$log->action];
                $failed = $log->action === StoryPlanApprovalService::EVENT_APPROVAL_FAILED;

                return [
                    'id' => $log->uuid,
                    'kind' => $isPlan ? 'production_plan' : 'story_plan',
                    'event' => $event,
                    'version_label' => $version === null ? null : 'Story version '.$version->version,
                    'role' => null,
                    'comment' => null,
                    'reel_title' => $version === null ? null : $titles->get($version->story_plan_id),
                    'scene_sequence' => null,
                    'scene_title' => null,
                    'reel_id' => null,
                    'scene_id' => null,
                    'capability' => null,
                    'provider_key' => null,
                    'model_key' => null,
                    'operation_id' => null,
                    'status' => $failed ? StoryVideoJobStatus::Failed->value : $event,
                    'error_code' => $failed && is_string($properties['error_code'] ?? null) ? $properties['error_code'] : null,
                    'error_message' => $failed && is_string($properties['reason'] ?? null) ? mb_substr($properties['reason'], 0, 300) : null,
                    'created_at' => $log->created_at?->toIso8601String(),
                    'submitted_at' => null,
                    'started_at' => null,
                    'completed_at' => null,
                    'failed_at' => $failed ? $log->created_at?->toIso8601String() : null,
                    'cost' => null,
                    'currency' => null,
                    'cost_source' => null,
                    'cost_reported' => false,
                    'ledger_recorded_at' => null,
                ];
            });
    }

    /**
     * @return array{amount: string|null, currency: string|null}
     */
    private function reportedCost(StoryVideoGenerationJob $job): array
    {
        $metadata = is_array($job->provider_metadata) ? $job->provider_metadata : [];
        $reported = $metadata['reported_cost'] ?? null;
        if (! is_array($reported) || ! isset($reported['amount']) || ! is_numeric($reported['amount'])) {
            return ['amount' => null, 'currency' => null];
        }
        $currency = isset($reported['currency']) && is_string($reported['currency'])
            && preg_match('/^[A-Za-z]{3}$/', $reported['currency']) === 1
            ? strtoupper($reported['currency'])
            : null;

        return ['amount' => (string) $reported['amount'], 'currency' => $currency];
    }
}
