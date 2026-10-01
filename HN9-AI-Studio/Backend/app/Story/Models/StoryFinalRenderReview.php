<?php

declare(strict_types=1);

namespace App\Story\Models;

use App\Models\Concerns\HasUuid;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $uuid
 * @property string $action
 * @property string|null $comment
 * @property string|null $target_kind
 * @property string|null $target_id
 */
class StoryFinalRenderReview extends Model
{
    use HasUuid;

    protected $table = 'story_final_render_reviews';

    protected $fillable = [
        'story_final_render_id',
        'user_id',
        'action',
        'comment',
        'target_kind',
        'target_id',
    ];

    /** @return BelongsTo<StoryFinalRender, $this> */
    public function render(): BelongsTo
    {
        return $this->belongsTo(StoryFinalRender::class, 'story_final_render_id');
    }

    /** @return BelongsTo<User, $this> */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
