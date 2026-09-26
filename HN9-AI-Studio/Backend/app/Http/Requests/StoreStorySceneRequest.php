<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Story\Support\StorySceneTimingNormalizer;
use Illuminate\Foundation\Http\FormRequest;

class StoreStorySceneRequest extends FormRequest
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
            'title' => ['nullable', 'string', 'max:200'],
            'duration_seconds' => [
                'nullable',
                'integer',
                'min:'.StorySceneTimingNormalizer::MIN_DURATION_SECONDS,
                'max:'.StorySceneTimingNormalizer::MAX_DURATION_SECONDS,
            ],
            'sequence' => ['nullable', 'integer', 'min:1'],
            'story' => ['nullable', 'string', 'max:20000'],
            'characters' => ['nullable', 'array'],
            'characters.*' => ['string', 'max:200'],
            'location' => ['nullable', 'string', 'max:500'],
            'dialogue' => ['nullable', 'array'],
            'narration' => ['nullable', 'string', 'max:10000'],
            'visual_prompt' => ['nullable', 'string', 'max:10000'],
            'motion_prompt' => ['nullable', 'string', 'max:10000'],
            'audio_direction' => ['nullable', 'string', 'max:5000'],
            'continuity' => ['nullable', 'array'],
            'continuity.previous_scene' => ['nullable', 'string', 'max:2000'],
            'continuity.next_scene' => ['nullable', 'string', 'max:2000'],
            'continuity.character_state' => ['nullable', 'string', 'max:2000'],
            'continuity.environment_state' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
