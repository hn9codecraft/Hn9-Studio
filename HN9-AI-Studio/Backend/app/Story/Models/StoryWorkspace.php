<?php

declare(strict_types=1);

namespace App\Story\Models;

use App\Models\Concerns\HasUuid;
use App\Models\Project;
use App\Story\Enums\StoryWorkspaceStatus;
use Database\Factories\StoryWorkspaceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Persistent Project Story workspace for one existing project.
 *
 * @property int $id
 * @property string $uuid
 * @property int $project_id
 * @property string $status
 */
class StoryWorkspace extends Model
{
    /** @use HasFactory<StoryWorkspaceFactory> */
    use HasFactory, HasUuid;

    protected $table = 'story_workspaces';

    protected $fillable = [
        'project_id',
        'status',
    ];

    protected static function newFactory(): StoryWorkspaceFactory
    {
        return StoryWorkspaceFactory::new();
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** @return HasOne<StoryBible, $this> */
    public function bible(): HasOne
    {
        return $this->hasOne(StoryBible::class);
    }

    /** @return HasMany<StoryCharacter, $this> */
    public function characters(): HasMany
    {
        return $this->hasMany(StoryCharacter::class);
    }

    /** @return HasOne<StoryStyleBible, $this> */
    public function styleBible(): HasOne
    {
        return $this->hasOne(StoryStyleBible::class);
    }

    /** @return HasMany<\App\Story\Models\StoryPlan, $this> */
    public function plans(): HasMany
    {
        return $this->hasMany(\App\Story\Models\StoryPlan::class);
    }

    /** @return HasMany<\App\Story\Models\StoryReel, $this> */
    public function reels(): HasMany
    {
        return $this->hasMany(\App\Story\Models\StoryReel::class);
    }

    public function statusEnum(): StoryWorkspaceStatus
    {
        return StoryWorkspaceStatus::tryFrom((string) $this->status) ?? StoryWorkspaceStatus::Ready;
    }
}
