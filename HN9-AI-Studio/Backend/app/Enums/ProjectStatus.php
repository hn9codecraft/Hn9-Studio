<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\InteractsWithEnum;

/**
 * Lifecycle of a project. Mirrors the `status` column on the `projects` table
 * (default `draft`).
 */
enum ProjectStatus: string
{
    use InteractsWithEnum;

    case Draft = 'draft';
    case Active = 'active';
    case Completed = 'completed';
    case Archived = 'archived';

    /**
     * Whether the project accepts further edits/generation requests.
     */
    public function isEditable(): bool
    {
        return in_array($this, [self::Draft, self::Active], true);
    }

    /**
     * Statuses a project in this state may transition to.
     *
     * @return list<self>
     */
    public function transitionsTo(): array
    {
        return match ($this) {
            self::Draft => [self::Active, self::Archived],
            self::Active => [self::Completed, self::Archived],
            self::Completed => [self::Archived],
            self::Archived => [self::Active],
        };
    }

    /**
     * Whether a transition from this status to the target is allowed.
     */
    public function canTransitionTo(self $target): bool
    {
        return $target === $this || in_array($target, $this->transitionsTo(), true);
    }
}
