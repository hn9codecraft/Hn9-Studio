<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ScriptReviewAction;
use App\Enums\ScriptStatus;
use App\Models\Script;
use App\Models\ScriptReviewEvent;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ScriptReviewEvent>
 */
class ScriptReviewEventFactory extends Factory
{
    protected $model = ScriptReviewEvent::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'script_id' => Script::factory(),
            'user_id' => User::factory(),
            'action' => ScriptReviewAction::SubmittedForReview->value,
            'comment' => null,
            'from_status' => ScriptStatus::Draft->value,
            'to_status' => ScriptStatus::PendingReview->value,
            'created_at' => now(),
        ];
    }
}
