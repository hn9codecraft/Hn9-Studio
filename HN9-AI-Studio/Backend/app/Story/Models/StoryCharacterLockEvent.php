<?php

declare(strict_types=1);

namespace App\Story\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $action
 * @property string $identity_hash
 * @property int|null $reference_id
 */
class StoryCharacterLockEvent extends Model
{
    public $timestamps = false;

    protected $table = 'story_character_lock_events';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<StoryCharacter, $this> */
    public function character(): BelongsTo
    {
        return $this->belongsTo(StoryCharacter::class, 'story_character_id');
    }

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
