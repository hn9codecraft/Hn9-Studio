<?php

declare(strict_types=1);

namespace App\AI\Support;

use App\Enums\Concerns\InteractsWithEnum;

/**
 * The health state of an AI provider as reported by a health check.
 */
enum HealthStatus: string
{
    use InteractsWithEnum;

    case Healthy = 'healthy';
    case Degraded = 'degraded';
    case Unavailable = 'unavailable';
    case Unknown = 'unknown';

    /**
     * Whether a provider in this state can serve requests.
     */
    public function isOperational(): bool
    {
        return in_array($this, [self::Healthy, self::Degraded], true);
    }
}
