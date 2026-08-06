<?php

declare(strict_types=1);

namespace App\AI\Exceptions;

use App\AI\Support\Capability;

/**
 * Thrown when every provider capable of serving a request estimates above the
 * configured (or caller-supplied) budget. Raised during routing, before any
 * vendor is contacted, so the spend never happens.
 */
final class BudgetExceededException extends AIException
{
    /**
     * @param  array<string, float>  $estimates  Provider key => estimated cost.
     */
    public static function forCapability(Capability $capability, float $budget, array $estimates, string $currency = 'USD'): self
    {
        return new self(
            message: "No AI provider can serve the [{$capability->value}] capability within the configured budget.",
            errorCode: 'ai_budget_exceeded',
            statusCode: 422,
            context: [
                'capability' => $capability->value,
                'budget' => $budget,
                'currency' => $currency,
                'estimates' => $estimates,
            ],
        );
    }
}
