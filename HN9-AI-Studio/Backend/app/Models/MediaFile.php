<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Database\Factories\MediaFileFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;

/**
 * @property int $id
 * @property string $uuid
 * @property string $disk
 * @property string $path
 * @property string|null $collection
 */
class MediaFile extends Model
{
    /** @use HasFactory<MediaFileFactory> */
    use HasFactory, HasUuid, SoftDeletes;

    protected $fillable = [
        'disk',
        'path',
        'original_name',
        'mime_type',
        'extension',
        'size',
        'checksum',
        'collection',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'size' => 'integer',
            'meta' => 'array',
        ];
    }

    /** @return MorphTo<Model, $this> */
    public function mediable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Resolve a public URL for the file on its configured disk. Returns null
     * for private disks that do not expose URLs (they throw at runtime).
     */
    public function url(): ?string
    {
        try {
            return Storage::disk($this->disk)->url($this->path);
        } catch (\Throwable) {
            return null;
        }
    }
}
