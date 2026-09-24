<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ScriptReviewAction;
use App\Enums\ScriptStatus;
use App\Models\Concerns\HasStatus;
use App\Models\Concerns\HasUuid;
use App\Models\Concerns\LogsActivity;
use Database\Factories\ScriptFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A studio script belonging to a project. May be authored manually or created
 * from the generation pipeline (`source=ai`) without replacing the other.
 *
 * @property int $id
 * @property string $uuid
 * @property int $project_id
 * @property string $title
 * @property string|null $body
 * @property string $status
 * @property string $source
 * @property int|null $generated_content_id
 * @property int|null $parent_script_id
 * @property array<string, mixed>|null $generation
 */
class Script extends Model
{
    /** @use HasFactory<ScriptFactory> */
    use HasFactory, HasStatus, HasUuid, LogsActivity, SoftDeletes;

    protected $fillable = [
        'project_id',
        'title',
        'body',
        'status',
        'source',
        'generated_content_id',
        'parent_script_id',
        'generation',
    ];

    protected function casts(): array
    {
        return [
            'generation' => 'array',
        ];
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** @return BelongsTo<GeneratedContent, $this> */
    public function generatedContent(): BelongsTo
    {
        return $this->belongsTo(GeneratedContent::class);
    }

    /** @return BelongsTo<Script, $this> */
    public function parentScript(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_script_id');
    }

    /** @return HasMany<Script, $this> */
    public function variations(): HasMany
    {
        return $this->hasMany(self::class, 'parent_script_id');
    }

    /** @return HasMany<ScriptReviewEvent, $this> */
    public function reviewEvents(): HasMany
    {
        return $this->hasMany(ScriptReviewEvent::class);
    }

    /** @return HasOne<ScriptReviewEvent, $this> */
    public function latestReviewEvent(): HasOne
    {
        return $this->hasOne(ScriptReviewEvent::class)->latestOfMany();
    }

    /** @return HasOne<ScriptReviewEvent, $this> */
    public function latestReworkEvent(): HasOne
    {
        return $this->hasOne(ScriptReviewEvent::class)->ofMany(
            ['id' => 'max'],
            fn ($query) => $query->where('action', ScriptReviewAction::NeedsRework->value),
        );
    }

    public function statusEnum(): ScriptStatus
    {
        return ScriptStatus::tryFrom((string) $this->status) ?? ScriptStatus::Draft;
    }

    public function allowsContentEdit(): bool
    {
        return $this->statusEnum()->allowsContentEdit();
    }

    public function isSubmittable(): bool
    {
        return $this->statusEnum()->isSubmittable();
    }

    public function isReviewable(): bool
    {
        return $this->statusEnum()->isReviewable();
    }

    public function allowsRegeneration(): bool
    {
        return $this->statusEnum()->allowsRegeneration();
    }
}
