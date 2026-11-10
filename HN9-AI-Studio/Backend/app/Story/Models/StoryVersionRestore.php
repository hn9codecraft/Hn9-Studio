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
 * @property string $subject_type
 * @property int $subject_id
 * @property int|null $previous_id
 * @property int|null $restored_by
 * @property int $timeline_clips_updated
 */
class StoryVersionRestore extends Model
{
    use HasUuid;

    public const UPDATED_AT = null;

    protected $table = 'story_version_restores';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'subject_id' => 'integer',
            'previous_id' => 'integer',
            'timeline_clips_updated' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function restorer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'restored_by');
    }
}
