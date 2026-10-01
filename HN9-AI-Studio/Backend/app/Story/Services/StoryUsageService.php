<?php

declare(strict_types=1);

namespace App\Story\Services;

use App\AI\Support\ProviderErrorSanitizer;
use App\Enums\CostSource;
use App\Models\Project;
use App\Story\Enums\StoryVideoJobStatus;
use App\Story\Models\StoryUsageLedgerEntry;
use App\Story\Models\StoryVideoGenerationJob;

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

        return $jobs->map(function (StoryVideoGenerationJob $job) use ($ledger): array {
            $entry = $ledger->get($job->id);

            return [
                'id' => $job->uuid,
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
        })->values()->all();
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
