<?php

declare(strict_types=1);

namespace App\Story\Models;

use App\Models\Concerns\HasUuid;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property string $uuid
 * @property int $story_workspace_id
 * @property int $story_character_id
 * @property int $story_character_reference_id
 * @property string $identity_text
 * @property string $identity_hash
 * @property array<string, mixed> $locked_fields
 */
class StoryCharacterLock extends Model
{
    use HasUuid;

    protected $table = 'story_character_locks';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'locked_fields' => 'array',
            'locked_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<StoryCharacter, $this> */
    public function character(): BelongsTo
    {
        return $this->belongsTo(StoryCharacter::class, 'story_character_id');
    }

    /** @return BelongsTo<StoryCharacterReference, $this> */
    public function reference(): BelongsTo
    {
        return $this->belongsTo(StoryCharacterReference::class, 'story_character_reference_id');
    }

    /** @return BelongsTo<User, $this> */
    public function lockedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'locked_by');
    }
}
