<?php

declare(strict_types=1);

namespace App\Story\Services;

use App\AI\Support\ProviderErrorSanitizer;
use App\Enums\CostSource;
use App\Models\Project;
use App\Story\Enums\StoryVideoCapability;
use App\Story\Enums\StoryVideoJobStatus;
use App\Story\Models\StoryFinalRender;
use App\Story\Models\StoryGenerationAttempt;
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
            ->with(['reel', 'scene'])
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
                'kind' => $job->capability === StoryVideoCapability::Audio->value ? 'audio' : 'video',
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

        return $items
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
