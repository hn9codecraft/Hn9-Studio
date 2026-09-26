<?php

declare(strict_types=1);

namespace App\Story\Models;

use App\Models\Concerns\HasUuid;
use Database\Factories\StoryStyleBibleFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $uuid
 * @property int $story_workspace_id
 * @property int|null $approved_reference_id
 */
class StoryStyleBible extends Model
{
    /** @use HasFactory<StoryStyleBibleFactory> */
    use HasFactory, HasUuid;

    protected $table = 'story_style_bibles';

    protected $fillable = [
        'story_workspace_id',
        'visual_style',
        'animation_style',
        'lighting',
        'camera_style',
        'color_direction',
        'environment_style',
        'mood',
        'rendering_style',
        'visual_quality',
        'art_direction_notes',
        'aspect_ratio',
        'approved_reference_id',
    ];

    protected static function newFactory(): StoryStyleBibleFactory
    {
        return StoryStyleBibleFactory::new();
    }

    /** @return BelongsTo<StoryWorkspace, $this> */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(StoryWorkspace::class, 'story_workspace_id');
    }

    /** @return HasMany<StoryStyleReference, $this> */
    public function references(): HasMany
    {
        return $this->hasMany(StoryStyleReference::class, 'story_style_bible_id');
    }

    /** @return BelongsTo<StoryStyleReference, $this> */
    public function approvedReference(): BelongsTo
    {
        return $this->belongsTo(StoryStyleReference::class, 'approved_reference_id');
    }

    public function isConfigured(): bool
    {
        foreach ([
            'visual_style', 'animation_style', 'lighting', 'camera_style',
            'color_direction', 'environment_style', 'mood', 'rendering_style',
            'visual_quality', 'art_direction_notes', 'aspect_ratio',
        ] as $field) {
            if (is_string($this->{$field}) && trim($this->{$field}) !== '') {
                return true;
            }
        }

        return false;
    }
}
