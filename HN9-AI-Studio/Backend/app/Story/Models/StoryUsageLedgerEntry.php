<?php

declare(strict_types=1);

namespace App\Story\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property string $uuid
 * @property int $story_video_generation_job_id
 * @property string $status
 * @property string|null $cost
 * @property string|null $currency
 * @property string|null $cost_source
 * @property \Illuminate\Support\Carbon $recorded_at
 */
class StoryUsageLedgerEntry extends Model
{
    use HasUuid;

    protected $table = 'story_usage_ledger_entries';

    protected $fillable = [
        'story_video_generation_job_id',
        'story_workspace_id',
        'capability',
        'provider_key',
        'model_key',
        'operation_id',
        'status',
        'cost',
        'currency',
        'cost_source',
        'recorded_at',
    ];

    protected function casts(): array
    {
        return [
            'cost' => 'decimal:6',
            'recorded_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<StoryVideoGenerationJob, $this> */
    public function job(): BelongsTo
    {
        return $this->belongsTo(StoryVideoGenerationJob::class, 'story_video_generation_job_id');
    }
}
