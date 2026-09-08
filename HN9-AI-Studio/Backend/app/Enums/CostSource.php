<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\InteractsWithEnum;

/**
 * Why a prompt_executions.cost value exists. Missing source means cost is unknown,
 * not zero spend.
 */
enum CostSource: string
{
    use InteractsWithEnum;

    case ProviderReported = 'provider_reported';
    case ConfiguredPricing = 'configured_pricing';
}
