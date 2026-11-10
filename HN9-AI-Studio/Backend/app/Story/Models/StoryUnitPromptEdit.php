<?php

declare(strict_types=1);

namespace App\Story\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $story_production_unit_id
 * @property string $prompt
 * @property string $base_hash
 */
class StoryUnitPromptEdit extends Model
{
    protected $table = 'story_unit_prompt_edits';

    protected $guarded = ['id'];

    /** @return BelongsTo<StoryProductionUnit, $this> */
    public function unit(): BelongsTo
    {
        return $this->belongsTo(StoryProductionUnit::class, 'story_production_unit_id');
    }

    /** @return BelongsTo<User, $this> */
    public function editor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'edited_by');
    }
}
