<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Story\Support\StorySceneTimingNormalizer;
use Illuminate\Foundation\Http\FormRequest;

class UpdateStorySceneRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'title' => ['sometimes', 'nullable', 'string', 'max:200'],
            'duration_seconds' => [
                'sometimes',
                'integer',
                'min:'.StorySceneTimingNormalizer::MIN_DURATION_SECONDS,
                'max:'.StorySceneTimingNormalizer::MAX_DURATION_SECONDS,
            ],
            'story' => ['sometimes', 'nullable', 'string', 'max:20000'],
            'characters' => ['sometimes', 'nullable', 'array'],
            'characters.*' => ['string', 'max:200'],
            'location' => ['sometimes', 'nullable', 'string', 'max:500'],
            'dialogue' => ['sometimes', 'nullable', 'array'],
            'narration' => ['sometimes', 'nullable', 'string', 'max:10000'],
            'visual_prompt' => ['sometimes', 'nullable', 'string', 'max:10000'],
            'motion_prompt' => ['sometimes', 'nullable', 'string', 'max:10000'],
            'audio_direction' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'continuity' => ['sometimes', 'nullable', 'array'],
            'continuity.previous_scene' => ['nullable', 'string', 'max:2000'],
            'continuity.next_scene' => ['nullable', 'string', 'max:2000'],
            'continuity.character_state' => ['nullable', 'string', 'max:2000'],
            'continuity.environment_state' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
