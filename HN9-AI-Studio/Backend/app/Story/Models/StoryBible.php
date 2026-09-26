<?php

declare(strict_types=1);

namespace App\Story\Models;

use App\Models\Concerns\HasUuid;
use App\Story\Support\StoryBibleAudioDefaults;
use Database\Factories\StoryBibleFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Persistent project-level Story Bible for one Story Workspace.
 *
 * @property int $id
 * @property string $uuid
 * @property int $story_workspace_id
 * @property string|null $concept
 * @property string|null $genre
 * @property string|null $audience
 * @property string|null $language
 * @property string|null $tone
 * @property string|null $world
 * @property string|null $location
 * @property string|null $time_period
 * @property string|null $narrative_style
 * @property string|null $video_style
 * @property string|null $aspect_ratio
 * @property int|null $default_duration
 * @property array<string, mixed>|null $audio_defaults
 */
class StoryBible extends Model
{
    /** @use HasFactory<StoryBibleFactory> */
    use HasFactory, HasUuid;

    protected $table = 'story_bibles';

    protected $fillable = [
        'story_workspace_id',
        'concept',
        'genre',
        'audience',
        'language',
        'tone',
        'world',
        'location',
        'time_period',
        'narrative_style',
        'video_style',
        'aspect_ratio',
        'default_duration',
        'audio_defaults',
    ];

    protected function casts(): array
    {
        return [
            'default_duration' => 'integer',
            'audio_defaults' => 'array',
        ];
    }

    protected static function newFactory(): StoryBibleFactory
    {
        return StoryBibleFactory::new();
    }

    /** @return BelongsTo<StoryWorkspace, $this> */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(StoryWorkspace::class, 'story_workspace_id');
    }

    /**
     * @return array<string, bool|string|null>
     */
    public function audioDefaults(): array
    {
        return StoryBibleAudioDefaults::normalize(is_array($this->audio_defaults) ? $this->audio_defaults : null);
    }

    public function isConfigured(): bool
    {
        foreach ([
            'concept', 'genre', 'audience', 'language', 'tone', 'world', 'location',
            'time_period', 'narrative_style', 'video_style', 'aspect_ratio',
        ] as $field) {
            if (is_string($this->{$field}) && $this->{$field} !== '') {
                return true;
            }
        }

        if ($this->default_duration !== null) {
            return true;
        }

        foreach ($this->audioDefaults() as $value) {
            if ($value !== null) {
                return true;
            }
        }

        return false;
    }
}
