<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\ProviderSettingFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A single configuration entry for an AI provider. The `value` is always
 * encrypted at rest (these entries hold API keys, tokens and endpoints), so
 * `is_secret` governs masking in responses/UI rather than storage.
 *
 * @property int $id
 * @property int $ai_provider_id
 * @property string $key
 * @property bool $is_secret
 * @property string $environment
 */
class ProviderSetting extends Model
{
    /** @use HasFactory<ProviderSettingFactory> */
    use HasFactory;

    protected $fillable = [
        'ai_provider_id',
        'key',
        'value',
        'is_secret',
        'environment',
    ];

    protected function casts(): array
    {
        return [
            'is_secret' => 'boolean',
            'value' => 'encrypted',
        ];
    }

    /** @return BelongsTo<AiProvider, $this> */
    public function provider(): BelongsTo
    {
        return $this->belongsTo(AiProvider::class, 'ai_provider_id');
    }

    /**
     * The value with secrets masked — safe for API responses and logs.
     */
    public function maskedValue(): ?string
    {
        if (! $this->is_secret) {
            return $this->value;
        }

        return $this->value === null ? null : str_repeat('*', 8);
    }
}
