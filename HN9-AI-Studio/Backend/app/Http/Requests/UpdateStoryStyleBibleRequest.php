<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Story\Enums\StoryBibleAspectRatio;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateStoryStyleBibleRequest extends FormRequest
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
            'visual_style' => ['sometimes', 'nullable', 'string', 'max:255'],
            'animation_style' => ['sometimes', 'nullable', 'string', 'max:255'],
            'lighting' => ['sometimes', 'nullable', 'string', 'max:255'],
            'camera_style' => ['sometimes', 'nullable', 'string', 'max:255'],
            'color_direction' => ['sometimes', 'nullable', 'string', 'max:255'],
            'environment_style' => ['sometimes', 'nullable', 'string', 'max:255'],
            'mood' => ['sometimes', 'nullable', 'string', 'max:255'],
            'rendering_style' => ['sometimes', 'nullable', 'string', 'max:255'],
            'visual_quality' => ['sometimes', 'nullable', 'string', 'max:255'],
            'art_direction_notes' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'aspect_ratio' => ['sometimes', 'nullable', 'string', Rule::in(StoryBibleAspectRatio::values())],
        ];
    }

    protected function prepareForValidation(): void
    {
        $trimKeys = [
            'visual_style', 'animation_style', 'lighting', 'camera_style', 'color_direction',
            'environment_style', 'mood', 'rendering_style', 'visual_quality', 'art_direction_notes',
        ];

        $payload = [];
        foreach ($trimKeys as $key) {
            if ($this->has($key) && is_string($this->input($key))) {
                $trimmed = trim($this->input($key));
                $payload[$key] = $trimmed === '' ? null : $trimmed;
            }
        }

        if ($this->has('aspect_ratio') && is_string($this->input('aspect_ratio'))) {
            $ratio = trim($this->input('aspect_ratio'));
            $payload['aspect_ratio'] = $ratio === '' ? null : $ratio;
        }

        if ($payload !== []) {
            $this->merge($payload);
        }
    }
}
