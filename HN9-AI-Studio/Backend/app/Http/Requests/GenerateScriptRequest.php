<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class GenerateScriptRequest extends FormRequest
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
            'topic' => ['required', 'string', 'max:255'],
            'platform' => ['sometimes', 'nullable', 'string', 'max:100'],
            'language' => ['sometimes', 'string', 'max:10'],
            'goal' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'duration' => ['sometimes', 'nullable', 'string', 'max:50'],
            'audience' => ['sometimes', 'nullable', 'string', 'max:255'],
            'tone' => ['sometimes', 'nullable', 'string', 'max:100'],
            'cta' => ['sometimes', 'nullable', 'string', 'max:255'],
            'service' => ['sometimes', 'nullable', 'string', 'max:255'],
            'video_style' => ['sometimes', 'nullable', 'string', 'max:255'],
            'voice_style' => ['sometimes', 'nullable', 'string', 'max:255'],
            'brand_rules' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'key_points' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'additional_instructions' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'title' => ['sometimes', 'nullable', 'string', 'max:255'],
        ];
    }
}
