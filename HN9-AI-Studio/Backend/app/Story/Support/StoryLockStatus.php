<?php

declare(strict_types=1);

namespace App\Story\Support;

use App\Story\Models\StoryCharacter;
use App\Story\Models\StoryCharacterLock;

/**
 * applied: the clip used the character's current lock.
 * none: the clip was made with no lock.
 * changed_since: the lock was removed or replaced after the clip.
 */
final class StoryLockStatus
{
    /**
     * @param  array<string, mixed>|null  $provenance
     */
    public static function forProvenance(?array $provenance): string
    {
        $locks = is_array($provenance['locks'] ?? null) ? $provenance['locks'] : [];
        if ($locks === []) {
            return 'none';
        }

        foreach ($locks as $row) {
            if (! is_array($row)) {
                return 'changed_since';
            }
            $uuid = (string) ($row['character_uuid'] ?? '');
            $hash = (string) ($row['identity_hash'] ?? '');
            $character = $uuid === '' ? null : StoryCharacter::query()->where('uuid', $uuid)->first();
            $current = $character?->activeLock;
            if (! $current instanceof StoryCharacterLock || ! hash_equals((string) $current->identity_hash, $hash)) {
                return 'changed_since';
            }
        }

        return 'applied';
    }
}
