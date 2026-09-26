<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Story\Enums\StoryVideoCapability;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PrepareStoryVideoJobRequest extends FormRequest
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
            'capability' => ['required', 'string', Rule::in(StoryVideoCapability::values())],
            'reel_id' => ['nullable', 'uuid'],
            'scene_id' => ['nullable', 'uuid'],
            'prompt' => ['nullable', 'string', 'max:10000'],
            'duration_seconds' => ['nullable', 'integer', 'min:1', 'max:3600'],
            'aspect_ratio' => ['nullable', 'string', 'max:20'],
            'resolution' => ['nullable', 'string', 'max:40'],
            'audio_requested' => ['sometimes', 'boolean'],
            'preferred_provider' => ['nullable', 'string', 'max:100'],
            'preferred_model' => ['nullable', 'string', 'max:100'],
            'idempotency_key' => ['nullable', 'string', 'max:100'],
            'inputs' => ['nullable', 'array'],
            'inputs.*.type' => ['required_with:inputs', 'string', 'max:40'],
            'inputs.*.asset_id' => ['nullable', 'string', 'max:100'],
            'inputs.*.role' => ['nullable', 'string', 'max:40'],
            'inputs.*.order' => ['nullable', 'integer', 'min:0'],
        ];
    }
}
