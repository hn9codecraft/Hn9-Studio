<?php

declare(strict_types=1);

namespace App\Story\Services;

use App\Models\Project;
use App\Story\Exceptions\StoryBudgetExceededException;
use App\Story\Models\StoryWorkspace;
use App\Story\Support\StoryCostEstimate;
use Illuminate\Support\Facades\DB;

/**
 * Blocks a paid action before the provider is called when the high estimate
 * would pass the project budget. No budget, or an unknown price, is allowed.
 */
final readonly class StoryBudgetGuard
{
    public function __construct(private StorySpendService $spend) {}

    public function assertWithinBudget(Project $project, StoryCostEstimate $estimate): void
    {
        $workspace = StoryWorkspace::query()->where('project_id', $project->id)->first();
        if (! $workspace instanceof StoryWorkspace || $workspace->budget_amount === null) {
            return;
        }
        if (! $estimate->available || $estimate->amountUsdHigh === null) {
            return;
        }

        $currency = strtoupper((string) ($workspace->budget_currency ?: 'INR'));
        $budgetUsd = $this->toUsd((float) $workspace->budget_amount, $currency);
        $committed = $this->spend->committedUsd($project);
        if ($committed + $estimate->amountUsdHigh <= $budgetUsd + 0.0000001) {
            return;
        }

        $remainingUsd = round($budgetUsd - $this->spend->summary($project)['spent_total_usd'], 4);
        throw StoryBudgetExceededException::overBudget(
            $estimate,
            (float) $this->spend->summary($project)['spent_total_usd'],
            (float) $workspace->budget_amount,
            $this->fromUsd($remainingUsd, $currency),
            $currency,
        );
    }

    /**
     * @template T
     * @param  callable(): T  $create
     * @return T
     */
    public function lockAndAssert(Project $project, StoryCostEstimate $estimate, callable $create): mixed
    {
        return DB::transaction(function () use ($project, $estimate, $create) {
            $workspace = StoryWorkspace::query()->where('project_id', $project->id)->lockForUpdate()->first();
            if ($workspace instanceof StoryWorkspace) {
                $this->assertWithinBudget($project, $estimate);
            }

            return $create();
        });
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
}
