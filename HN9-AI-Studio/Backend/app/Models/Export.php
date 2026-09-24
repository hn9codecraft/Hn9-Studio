<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ExportStatus;
use App\Models\Concerns\HasUuid;
use App\Models\Concerns\LogsActivity;
use Database\Factories\ExportFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A project export package stored on the private exports disk.
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
 * @property string $fingerprint
 * @property string|null $error
 * @property array<string, mixed>|null $manifest
 */
class Export extends Model
{
    /** @use HasFactory<ExportFactory> */
    use HasFactory, HasUuid, LogsActivity, SoftDeletes;

    protected $fillable = [
        'user_id',
        'project_id',
        'status',
        'disk',
        'path',
        'filename',
        'size',
        'fingerprint',
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

    public function statusEnum(): ExportStatus
    {
        return ExportStatus::tryFrom((string) $this->status) ?? ExportStatus::Failed;
    }
}
