<?php

declare(strict_types=1);

namespace App\Story\Models;

use App\Models\Concerns\HasUuid;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $body
 */
class StoryReelComment extends Model
{
    use HasUuid;

    protected $table = 'story_reel_comments';

    protected $fillable = [
        'story_reel_version_id',
        'user_id',
        'body',
    ];

    /** @return BelongsTo<StoryReelVersion, $this> */
    public function version(): BelongsTo
    {
        return $this->belongsTo(StoryReelVersion::class, 'story_reel_version_id');
    }

    /** @return BelongsTo<User, $this> */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
