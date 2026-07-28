<?php

declare(strict_types=1);

namespace App\Services\Logging;

use App\Contracts\Logging\ActivityLoggerInterface;
use App\Models\ActivityLog;
use App\Models\User;
use App\Repositories\Contracts\ActivityLogRepositoryInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

/**
 * Default activity logger. Persists audit entries through the activity-log
 * repository and enriches them with the current request context when
 * available. This is the single write path for the audit trail.
 */
final readonly class ActivityLogger implements ActivityLoggerInterface
{
    public function __construct(
        private ActivityLogRepositoryInterface $activityLogs,
        private Request $request,
    ) {}

    public function log(
        string $action,
        ?Model $subject = null,
        ?User $causer = null,
        ?string $description = null,
        array $properties = [],
    ): ActivityLog {
        $attributes = [
            'user_id' => $causer?->getKey(),
            'action' => $action,
            'description' => $description,
            'properties' => $properties,
            'ip_address' => $this->request->ip(),
            'user_agent' => $this->request->userAgent(),
        ];

        if ($subject !== null) {
            $attributes['subject_type'] = $subject->getMorphClass();
            $attributes['subject_id'] = $subject->getKey();
        }

        return $this->activityLogs->create($attributes);
    }
}
