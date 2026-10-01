<?php

declare(strict_types=1);

namespace App\Story\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property string $uuid
 * @property string $status
 * @property string $review_status
 * @property string $timeline_version
 * @property array<string, mixed> $timeline_snapshot
 * @property string|null $disk
 * @property string|null $path
 * @property string|null $mime
 * @property int|null $size_bytes
 * @property string|null $checksum
 * @property string|null $error_code
 * @property string|null $error_message
 */
class StoryFinalRender extends Model
{
    use HasUuid;

    protected $table = 'story_final_renders';

    protected $fillable = [
        'story_timeline_id',
        'story_reel_id',
        'status',
        'review_status',
        'timeline_version',
        'timeline_snapshot',
        'disk',
        'path',
        'mime',
        'size_bytes',
        'checksum',
        'error_code',
        'error_message',
    ];

    protected function casts(): array
    {
        return [
            'timeline_snapshot' => 'array',
            'size_bytes' => 'integer',
        ];
    }

    /** @return BelongsTo<StoryTimeline, $this> */
    public function timeline(): BelongsTo
    {
        return $this->belongsTo(StoryTimeline::class, 'story_timeline_id');
    }

    /** @return BelongsTo<StoryReel, $this> */
    public function reel(): BelongsTo
    {
        return $this->belongsTo(StoryReel::class, 'story_reel_id');
    }
}
