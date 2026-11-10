<?php

declare(strict_types=1);

namespace App\Story\Services;

use App\Enums\CostSource;
use App\Models\Project;
use App\Story\Enums\StoryVideoJobStatus;
use App\Story\Models\StoryCharacterReference;
use App\Story\Models\StoryStyleReference;
use App\Story\Models\StoryUsageLedgerEntry;
use App\Story\Models\StoryVideoGenerationJob;
use App\Story\Models\StoryWorkspace;

/**
 * Read-only project spend. Reported provider cost and configured estimates stay apart.
 */
final readonly class StorySpendService
{
    /**
     * @return array<string, mixed>
     */
    public function summary(Project $project): array
    {
        $workspace = StoryWorkspace::query()->where('project_id', $project->id)->first();
        $display = (string) config('story_cost.display_currency', 'INR');
        if (! $workspace instanceof StoryWorkspace) {
            return $this->empty($display);
        }

        $jobs = StoryVideoGenerationJob::query()->where('story_workspace_id', $workspace->id)->get();
        $reportedByJob = $this->reportedByJob($workspace->id);
        $groups = [];
        $reported = 0.0;
        $estimated = 0.0;
        $reserved = 0.0;
        $unavailable = 0;

        foreach ($jobs as $job) {
            $reached = $this->reached($job);
            $failedBeforeProvider = ! $reached && in_array($job->statusEnum(), [StoryVideoJobStatus::Failed, StoryVideoJobStatus::Cancelled], true);
            $reportedCost = $reportedByJob[$job->id] ?? null;
            $estimate = is_array($job->provider_metadata['cost_estimate'] ?? null) ? $job->provider_metadata['cost_estimate'] : null;
            $estimateAmount = is_array($estimate) && is_numeric($estimate['amount_usd'] ?? null) && ($estimate['available'] ?? false) ? (float) $estimate['amount_usd'] : null;

            if ($reportedCost !== null) {
                $reported += $reportedCost;
            } elseif ($failedBeforeProvider) {
                continue;
            } elseif ($reached) {
                if ($estimateAmount === null) {
                    $unavailable++;
                } else {
                    $estimated += $estimateAmount;
                }
            } elseif ($estimateAmount !== null) {
                $reserved += $estimateAmount;
            }

            if (! $reached && $reportedCost === null) {
                continue;
            }

            $key = (string) ($job->provider_key ?: 'unknown').'|'.(string) $job->capability;
            if (! isset($groups[$key])) {
                $groups[$key] = [
                    'provider_key' => (string) ($job->provider_key ?: 'unknown'),
                    'label' => $this->label((string) $job->provider_key),
                    'capability' => (string) $job->capability,
                    'jobs' => 0,
                    'reported_usd' => 0.0,
                    'estimated_usd' => 0.0,
                ];
            }
            $groups[$key]['jobs']++;
            if ($reportedCost !== null) {
                $groups[$key]['reported_usd'] = round($groups[$key]['reported_usd'] + $reportedCost, 4);
            } elseif ($reached && $estimateAmount !== null) {
                $groups[$key]['estimated_usd'] = round($groups[$key]['estimated_usd'] + $estimateAmount, 4);
            }
        }

        $pictures = $this->generatedPictures($workspace->id);
        $imageRate = config('story_cost.image.default');
        $imageRate = is_numeric($imageRate) ? (float) $imageRate : null;
        if ($pictures > 0 && $imageRate === null) {
            $unavailable += $pictures;
        } elseif ($pictures > 0) {
            $pictureCost = round($pictures * $imageRate, 4);
            $estimated += $pictureCost;
            $groups['image.default|reference_image'] = [
                'provider_key' => 'image.default',
                'label' => $this->label('image.default'),
                'capability' => 'reference_image',
                'jobs' => $pictures,
                'reported_usd' => 0.0,
                'estimated_usd' => $pictureCost,
            ];
        }

        $reported = round($reported, 4);
        $estimated = round($estimated, 4);
        $total = round($reported + $estimated, 4);
        $budgetAmount = $workspace->budget_amount !== null ? (float) $workspace->budget_amount : null;
        $budgetCurrency = strtoupper((string) ($workspace->budget_currency ?: 'INR'));
        $budgetUsd = $budgetAmount === null ? null : $this->toUsd($budgetAmount, $budgetCurrency);
        $remainingUsd = $budgetUsd === null ? null : round($budgetUsd - $total, 4);

        return [
            'budget' => $budgetAmount === null ? null : [
                'amount' => $budgetAmount,
                'currency' => $budgetCurrency,
                'amount_usd' => $budgetUsd,
            ],
            'spent_reported_usd' => $reported,
            'spent_estimated_usd' => $estimated,
            'spent_total_usd' => $total,
            'spent_reported_display' => $this->display($reported, $display),
            'spent_estimated_display' => $this->display($estimated, $display),
            'spent_total_display' => $this->display($total, $display),
            'display_currency' => $display,
            'reserved_usd' => round($reserved, 4),
            'remaining' => $remainingUsd === null ? null : [
                'amount_usd' => $remainingUsd,
                'amount' => $this->fromUsd($remainingUsd, $budgetCurrency),
                'currency' => $budgetCurrency,
            ],
            'by_provider' => array_values($groups),
            'estimate_unavailable_jobs' => $unavailable,
        ];
    }

    /**
     * Reported plus estimates already stored on jobs that have not failed before the provider.
     */
    public function committedUsd(Project $project): float
    {
        $summary = $this->summary($project);

        return round((float) $summary['spent_total_usd'] + (float) $summary['reserved_usd'], 4);
    }

    /**
     * @return array<int, float>
     */
    private function reportedByJob(int $workspaceId): array
    {
        $rows = StoryUsageLedgerEntry::query()
            ->where('story_workspace_id', $workspaceId)
            ->where('cost_source', CostSource::ProviderReported->value)
            ->whereNotNull('cost')
            ->get();
        $sums = [];
        foreach ($rows as $row) {
            $usd = $this->toUsd((float) $row->cost, strtoupper((string) ($row->currency ?: 'USD')));
            $sums[$row->story_video_generation_job_id] = round(($sums[$row->story_video_generation_job_id] ?? 0) + $usd, 4);
        }

        return $sums;
    }

    private function generatedPictures(int $workspaceId): int
    {
        $characters = StoryCharacterReference::query()
            ->where('source', 'generated')
            ->whereHas('character', static function ($query) use ($workspaceId): void {
                $query->where('story_workspace_id', $workspaceId);
            })
            ->count();
        $styles = StoryStyleReference::query()
            ->where('source', 'generated')
            ->whereHas('styleBible', static function ($query) use ($workspaceId): void {
                $query->where('story_workspace_id', $workspaceId);
            })
            ->count();

        return $characters + $styles;
    }

    private function reached(StoryVideoGenerationJob $job): bool
    {
        return (is_string($job->operation_id) && $job->operation_id !== '') || $job->started_at !== null;
    }

    private function toUsd(float $amount, string $currency): float
    {
        if ($currency === 'INR') {
            $fx = (float) config('story_cost.fx.USD_INR', 94);

            return $fx > 0 ? $amount / $fx : $amount;
        }

        return $amount;
    }

    private function fromUsd(float $usd, string $currency): float
    {
        if ($currency === 'INR') {
            return round($usd * (float) config('story_cost.fx.USD_INR', 94), 2);
        }

        return round($usd, 2);
    }

    private function display(float $usd, string $currency): float
    {
        return $this->fromUsd($usd, strtoupper($currency));
    }

    private function label(string $provider): string
    {
        return match ($provider) {
            'video.runway' => 'Runway',
            'video.luma' => 'Luma',
            'video.seedance' => 'Seedance',
            'audio.elevenlabs' => 'ElevenLabs',
            'image.default' => 'Images',
            'text' => 'OpenAI/text',
            default => $provider !== '' ? $provider : 'Unknown',
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function empty(string $display): array
    {
        return [
            'budget' => null,
            'spent_reported_usd' => 0.0,
            'spent_estimated_usd' => 0.0,
            'spent_total_usd' => 0.0,
            'spent_reported_display' => 0.0,
            'spent_estimated_display' => 0.0,
            'spent_total_display' => 0.0,
            'display_currency' => $display,
            'reserved_usd' => 0.0,
            'remaining' => null,
            'by_provider' => [],
            'estimate_unavailable_jobs' => 0,
        ];
    }
}
