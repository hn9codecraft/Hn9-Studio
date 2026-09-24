<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Database\Factories\ImageReviewEventFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Append-only review/approval event for a studio image.
 *
 * @property int $id
 * @property string $uuid
 * @property int $image_id
 * @property int|null $user_id
 * @property string $action
 * @property string|null $comment
 * @property string $from_status
 * @property string $to_status
 */
class ImageReviewEvent extends Model
{
    /** @use HasFactory<ImageReviewEventFactory> */
    use HasFactory, HasUuid;

    public $timestamps = false;

    protected $fillable = [
        'image_id',
        'user_id',
        'action',
        'comment',
        'from_status',
        'to_status',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Image, $this> */
    public function image(): BelongsTo
    {
        return $this->belongsTo(Image::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
