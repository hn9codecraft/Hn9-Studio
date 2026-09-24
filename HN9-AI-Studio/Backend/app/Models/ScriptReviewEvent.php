<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Database\Factories\ScriptReviewEventFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Append-only review/approval event for a studio script.
 *
 * @property int $id
 * @property string $uuid
 * @property int $script_id
 * @property int|null $user_id
 * @property string $action
 * @property string|null $comment
 * @property string $from_status
 * @property string $to_status
 */
class ScriptReviewEvent extends Model
{
    /** @use HasFactory<ScriptReviewEventFactory> */
    use HasFactory, HasUuid;

    public $timestamps = false;

    protected $fillable = [
        'script_id',
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

    /** @return BelongsTo<Script, $this> */
    public function script(): BelongsTo
    {
        return $this->belongsTo(Script::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
