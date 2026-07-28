<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasStatus;
use App\Models\Concerns\HasUuid;
use Database\Factories\AiProviderFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property int $id
 * @property string $uuid
 * @property string $slug
 * @property string $name
 * @property string $category
 * @property string $status
 */
class AiProvider extends Model
{
    /** @use HasFactory<AiProviderFactory> */
    use HasFactory, HasStatus, HasUuid, SoftDeletes;

    protected $fillable = [
        'slug',
        'name',
        'category',
        'status',
        'base_url',
        'priority',
        'capabilities',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'capabilities' => 'array',
            'metadata' => 'array',
            'priority' => 'integer',
        ];
    }

    /** @return HasMany<ProviderSetting, $this> */
    public function settings(): HasMany
    {
        return $this->hasMany(ProviderSetting::class);
    }

    /** @return HasMany<AgentExecution, $this> */
    public function agentExecutions(): HasMany
    {
        return $this->hasMany(AgentExecution::class);
    }
}
