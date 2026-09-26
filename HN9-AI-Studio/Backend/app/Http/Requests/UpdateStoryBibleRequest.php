<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Story\Enums\StoryBibleAspectRatio;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateStoryBibleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $payload = [];

        foreach ([
            'concept', 'genre', 'audience', 'language', 'tone', 'world', 'location',
            'time_period', 'narrative_style', 'video_style', 'aspect_ratio',
        ] as $field) {
            if ($this->exists($field) && is_string($this->input($field))) {
                $trimmed = trim($this->input($field));
                $payload[$field] = $trimmed === '' ? null : $trimmed;
            }
        }

        if ($this->exists('default_duration') && $this->input('default_duration') === '') {
            $payload['default_duration'] = null;
        }

        if ($this->exists('audio_defaults') && is_array($this->input('audio_defaults'))) {
            $audio = $this->input('audio_defaults');
            foreach (['voice_style', 'music_mood'] as $field) {
                if (isset($audio[$field]) && is_string($audio[$field])) {
                    $trimmed = trim($audio[$field]);
                    $audio[$field] = $trimmed === '' ? null : $trimmed;
                }
            }
            $payload['audio_defaults'] = $audio;
        }

        if ($payload !== []) {
            $this->merge($payload);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'concept' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'genre' => ['sometimes', 'nullable', 'string', 'max:100'],
            'audience' => ['sometimes', 'nullable', 'string', 'max:255'],
            'language' => ['sometimes', 'nullable', 'string', 'max:10'],
            'tone' => ['sometimes', 'nullable', 'string', 'max:100'],
            'world' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'location' => ['sometimes', 'nullable', 'string', 'max:255'],
            'time_period' => ['sometimes', 'nullable', 'string', 'max:100'],
            'narrative_style' => ['sometimes', 'nullable', 'string', 'max:255'],
            'video_style' => ['sometimes', 'nullable', 'string', 'max:255'],
            'aspect_ratio' => ['sometimes', 'nullable', 'string', Rule::in(StoryBibleAspectRatio::values())],
            'default_duration' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:300'],
            'audio_defaults' => ['sometimes', 'nullable', 'array'],
            'audio_defaults.voice_enabled' => ['sometimes', 'nullable', 'boolean'],
            'audio_defaults.music_enabled' => ['sometimes', 'nullable', 'boolean'],
            'audio_defaults.sfx_enabled' => ['sometimes', 'nullable', 'boolean'],
            'audio_defaults.voice_style' => ['sometimes', 'nullable', 'string', 'max:100'],
            'audio_defaults.music_mood' => ['sometimes', 'nullable', 'string', 'max:100'],
        ];
    }
}
