<?php

declare(strict_types=1);

namespace App\Story\Models;

use App\Models\Concerns\HasUuid;
use App\Models\User;
use App\Story\Enums\StoryCharacterReferenceRole;
use App\Story\Enums\StoryCharacterReferenceSource;
use App\Story\Enums\StoryCharacterReferenceStatus;
use Database\Factories\StoryCharacterReferenceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property string $uuid
 * @property int $story_character_id
 * @property string $source
 * @property string $role
 * @property int $version
 * @property string $status
 * @property string $disk
 * @property string $path
 */
class StoryCharacterReference extends Model
{
    /** @use HasFactory<StoryCharacterReferenceFactory> */
    use HasFactory, HasUuid;

    protected $table = 'story_character_references';

    protected $fillable = [
        'story_character_id',
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

    protected static function newFactory(): StoryCharacterReferenceFactory
    {
        return StoryCharacterReferenceFactory::new();
    }

    /** @return BelongsTo<StoryCharacter, $this> */
    public function character(): BelongsTo
    {
        return $this->belongsTo(StoryCharacter::class, 'story_character_id');
    }

    /** @return BelongsTo<User, $this> */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function statusEnum(): StoryCharacterReferenceStatus
    {
        return StoryCharacterReferenceStatus::tryFrom((string) $this->status)
            ?? StoryCharacterReferenceStatus::Draft;
    }

    public function sourceEnum(): StoryCharacterReferenceSource
    {
        return StoryCharacterReferenceSource::tryFrom((string) $this->source)
            ?? StoryCharacterReferenceSource::Uploaded;
    }

    public function roleEnum(): StoryCharacterReferenceRole
    {
        return StoryCharacterReferenceRole::tryFrom((string) $this->role)
            ?? StoryCharacterReferenceRole::Primary;
    }
}
