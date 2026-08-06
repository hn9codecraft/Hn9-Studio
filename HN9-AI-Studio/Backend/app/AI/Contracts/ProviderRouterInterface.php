<?php

declare(strict_types=1);

namespace App\AI\Contracts;

use App\AI\Exceptions\BudgetExceededException;
use App\AI\Exceptions\CircuitOpenException;
use App\AI\Exceptions\NoProviderAvailableException;
use App\AI\Routing\RoutingContext;
use App\AI\Routing\RoutingPlan;

/**
 * Turns a routing context into an ordered plan of providers to try.
 *
 * The router filters by declared capability, configuration, model, health,
 * circuit state and budget, then ranks whatever survives with the active
 * {@see RoutingStrategyInterface}. It resolves no provider and issues no
 * request — planning is separate from execution.
 */
interface ProviderRouterInterface
{
    /**
     * Build the ordered plan for a dispatch.
     *
     * @throws NoProviderAvailableException When nothing can serve the capability.
     * @throws CircuitOpenException When every candidate is withheld by its circuit.
     * @throws BudgetExceededException When every candidate exceeds the budget.
     */
    public function route(RoutingContext $context): RoutingPlan;
}
