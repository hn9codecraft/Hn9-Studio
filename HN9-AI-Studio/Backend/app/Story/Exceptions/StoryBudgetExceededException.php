<?php

declare(strict_types=1);

namespace App\Story\Exceptions;

use App\Story\Support\StoryCostEstimate;

class StoryBudgetExceededException extends StoryException
{
    /**
     * @param  array<string, mixed>  $meta
     */
    public function __construct(string $message, array $meta)
    {
        parent::__construct(
            message: $message,
            errorCode: 'story_budget_exceeded',
            statusCode: 422,
            context: $meta,
        );
    }

    public static function overBudget(StoryCostEstimate $estimate, float $spentUsd, ?float $budgetAmount, ?float $remaining, string $currency): self
    {
        return new self(
            'This would go over the project budget, so nothing was sent.',
            [
                'estimate' => $estimate->toArray(),
                'spent_total' => $spentUsd,
                'budget' => $budgetAmount,
                'remaining' => $remaining,
                'currency' => $currency,
                'estimate_available' => $estimate->available,
            ],
        );
    }
}
