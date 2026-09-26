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
 * @property string $body
 */
class StorySceneComment extends Model
{
    use HasUuid;

    protected $table = 'story_scene_comments';

    protected $fillable = [
        'story_scene_version_id',
        'user_id',
        'body',
    ];

    /** @return BelongsTo<StorySceneVersion, $this> */
    public function version(): BelongsTo
    {
        return $this->belongsTo(StorySceneVersion::class, 'story_scene_version_id');
    }

    /** @return BelongsTo<User, $this> */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
