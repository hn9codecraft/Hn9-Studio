<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\InteractsWithEnum;

/**
 * Generic lifecycle status for configurable domain entities (e.g. AI
 * providers). Resource-specific lifecycles use their own enums
 * ({@see ProjectStatus}, {@see WorkflowStatus}, {@see ExecutionStatus}).
 */
enum Status: string
{
    use InteractsWithEnum;

    case Active = 'active';
    case Inactive = 'inactive';
    case Disabled = 'disabled';
    case Archived = 'archived';

    /**
     * Whether the entity is usable in normal operation.
     */
    public function isUsable(): bool
    {
        return $this === self::Active;
    }
}
