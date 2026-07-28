<?php

declare(strict_types=1);

namespace App\AI\Contracts;

use App\AI\DTOs\ProviderHealthDTO;

/**
 * Contract for anything that can report its own health. Segregated from
 * {@see AIProviderInterface} so health probing can be depended upon
 * independently of the full generation surface.
 */
interface ProviderHealthInterface
{
    /**
     * Probe the provider and return its current health.
     */
    public function healthCheck(): ProviderHealthDTO;
}
