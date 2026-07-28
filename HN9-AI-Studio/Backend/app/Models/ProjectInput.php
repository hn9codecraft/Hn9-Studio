<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\ProjectInputFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property int $id
 * @property int $project_id
 * @property string $type
 * @property string $language
 */
class ProjectInput extends Model
{
    /** @use HasFactory<ProjectInputFactory> */
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'project_id',
        'user_id',
        'type',
        'deliverable_type',
        'platform',
        'language',
        'topic',
        'goal',
        'payload',
        'source',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
        ];
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
