<?php

declare(strict_types=1);

namespace App\Story\Models;

use App\Models\Concerns\HasUuid;
use App\Models\User;
use App\Story\Enums\StoryStyleReferenceRole;
use App\Story\Enums\StoryStyleReferenceSource;
use App\Story\Enums\StoryStyleReferenceStatus;
use Database\Factories\StoryStyleReferenceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property string $uuid
 * @property int $story_style_bible_id
 * @property string $source
 * @property string $role
 * @property int $version
 * @property string $status
 * @property string $disk
 * @property string $path
 */
class StoryStyleReference extends Model
{
    /** @use HasFactory<StoryStyleReferenceFactory> */
    use HasFactory, HasUuid;

    protected $table = 'story_style_references';

    protected $fillable = [
        'story_style_bible_id',
        'source',
        'role',
        'version',
        'status',
        'disk',
        'path',
        'original_filename',
        'mime_type',
        'extension',
        'size',
        'width',
        'height',
        'checksum',
        'prompt',
        'provider',
        'model',
        'generation',
        'submitted_at',
        'reviewed_at',
        'reviewed_by',
        'review_comment',
    ];

    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'size' => 'integer',
            'width' => 'integer',
            'height' => 'integer',
            'generation' => 'array',
            'submitted_at' => 'datetime',
            'reviewed_at' => 'datetime',
        ];
    }

    protected static function newFactory(): StoryStyleReferenceFactory
    {
        return StoryStyleReferenceFactory::new();
    }

    /** @return BelongsTo<StoryStyleBible, $this> */
    public function styleBible(): BelongsTo
    {
        return $this->belongsTo(StoryStyleBible::class, 'story_style_bible_id');
    }

    /** @return BelongsTo<User, $this> */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function statusEnum(): StoryStyleReferenceStatus
    {
        return StoryStyleReferenceStatus::tryFrom((string) $this->status)
            ?? StoryStyleReferenceStatus::Draft;
    }

    public function sourceEnum(): StoryStyleReferenceSource
    {
        return StoryStyleReferenceSource::tryFrom((string) $this->source)
            ?? StoryStyleReferenceSource::Uploaded;
    }

    public function roleEnum(): StoryStyleReferenceRole
    {
        return StoryStyleReferenceRole::tryFrom((string) $this->role)
            ?? StoryStyleReferenceRole::Primary;
    }
}
