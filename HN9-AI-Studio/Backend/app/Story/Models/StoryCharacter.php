<?php

declare(strict_types=1);

namespace App\Story\Models;

use App\Models\Concerns\HasUuid;
use App\Story\Enums\StoryCharacterStatus;
use Database\Factories\StoryCharacterFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $uuid
 * @property int $story_workspace_id
 * @property string $name
 * @property string $status
 * @property int|null $approved_reference_id
 */
class StoryCharacter extends Model
{
    /** @use HasFactory<StoryCharacterFactory> */
    use HasFactory, HasUuid;

    protected $table = 'story_characters';

    protected $fillable = [
        'story_workspace_id',
        'name',
        'short_description',
        'age',
        'gender_presentation',
        'appearance',
        'face_description',
        'hair',
        'clothing',
        'personality',
        'voice_description',
        'special_details',
        'status',
        'sort_order',
        'approved_reference_id',
    ];

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
        ];
    }

    protected static function newFactory(): StoryCharacterFactory
    {
        return StoryCharacterFactory::new();
    }

    /** @return BelongsTo<StoryWorkspace, $this> */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(StoryWorkspace::class, 'story_workspace_id');
    }

    /** @return HasMany<StoryCharacterReference, $this> */
    public function references(): HasMany
    {
        return $this->hasMany(StoryCharacterReference::class, 'story_character_id');
    }

    /** @return BelongsTo<StoryCharacterReference, $this> */
    public function approvedReference(): BelongsTo
    {
        return $this->belongsTo(StoryCharacterReference::class, 'approved_reference_id');
    }

    public function statusEnum(): StoryCharacterStatus
    {
        return StoryCharacterStatus::tryFrom((string) $this->status) ?? StoryCharacterStatus::Draft;
    }

    public function allowsEdit(): bool
    {
        return $this->statusEnum()->allowsEdit();
    }
}
