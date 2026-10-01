<?php

declare(strict_types=1);

namespace App\Story\Models;

use App\Enums\ExportStatus;
use App\Models\Concerns\HasUuid;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A Story final package stored on the private exports disk.
 *
 * @property int $id
 * @property string $uuid
 * @property int $user_id
 * @property int $project_id
 * @property string $status
 * @property string $disk
 * @property string|null $path
 * @property string|null $filename
 * @property int|null $size
 * @property string|null $error
 * @property array<string, mixed>|null $manifest
 */
class StoryExport extends Model
{
    use HasUuid;

    protected $table = 'story_exports';

    protected $fillable = [
        'user_id',
        'project_id',
        'story_reel_id',
        'story_final_render_id',
        'status',
        'disk',
        'path',
        'filename',
        'size',
        'error',
        'manifest',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'size' => 'integer',
            'manifest' => 'array',
            'completed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** @return BelongsTo<StoryReel, $this> */
    public function reel(): BelongsTo
    {
        return $this->belongsTo(StoryReel::class, 'story_reel_id');
    }

    /** @return BelongsTo<StoryFinalRender, $this> */
    public function render(): BelongsTo
    {
        return $this->belongsTo(StoryFinalRender::class, 'story_final_render_id');
    }

    public function statusEnum(): ExportStatus
    {
        return ExportStatus::tryFrom((string) $this->status) ?? ExportStatus::Failed;
    }
}
