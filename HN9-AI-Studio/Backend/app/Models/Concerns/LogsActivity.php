<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Contracts\Logging\ActivityLoggerInterface;
use App\Models\ActivityLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * Exposes the append-only activity history recorded against a model (as the
 * polymorphic `subject`). This is the read side only — entries are written
 * through {@see ActivityLoggerInterface}, so no audit
 * (business) logic lives in the model.
 *
 * @mixin Model
 */
trait LogsActivity
{
    /**
     * Activity-log entries recorded against this model.
     *
     * @return MorphMany<ActivityLog, $this>
     */
    public function activities(): MorphMany
    {
        return $this->morphMany(ActivityLog::class, 'subject');
    }
}
