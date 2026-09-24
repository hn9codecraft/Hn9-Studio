<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class GenerateVideoRequest extends FormRequest
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
            'prompt' => ['required_without:script_id', 'nullable', 'string', 'max:4000'],
            'title' => ['sometimes', 'nullable', 'string', 'max:255'],
            'negative_prompt' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'aspect_ratio' => ['sometimes', 'string', Rule::in(['16:9', '9:16'])],
            'resolution' => ['sometimes', 'nullable', 'string', Rule::in(['720p', '1080p', '4k'])],
            'duration' => ['sometimes', 'nullable', 'integer', Rule::in([8])],
            'model' => ['sometimes', 'nullable', 'string', 'max:120'],
            'provider' => ['sometimes', 'nullable', 'string', Rule::in(['gemini'])],
            'script_id' => ['sometimes', 'nullable', 'uuid'],
            'image_id' => ['sometimes', 'nullable', 'uuid'],
        ];
    }
}
