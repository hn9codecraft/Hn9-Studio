<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use Illuminate\Support\Str;

/**
 * Auto-populates a model's `uuid` column on creation and exposes it as the
 * route key. Used for models that keep an auto-increment primary key for
 * internal joins while presenting a stable UUID to the outside world.
 */
trait HasUuid
{
    protected static function bootHasUuid(): void
    {
        static::creating(function ($model): void {
            if (empty($model->uuid)) {
                $model->uuid = (string) Str::uuid();
            }
        });
    }

    /**
     * Bind route-model parameters by UUID rather than the numeric id.
     */
    public function getRouteKeyName(): string
    {
        return 'uuid';
    }
}
