<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\ImageAspectRatio;
use App\Rules\EnumValue;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class GenerateImageRequest extends FormRequest
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
            'aspect_ratio' => ['sometimes', 'string', new EnumValue(ImageAspectRatio::class)],
            'size' => ['sometimes', 'nullable', 'string', 'regex:/^\d{2,5}x\d{2,5}$/'],
            'quality' => ['sometimes', 'nullable', 'string', 'max:32'],
            'model' => ['sometimes', 'nullable', 'string', 'max:120'],
            'provider' => ['sometimes', 'nullable', 'string', Rule::in(['openai', 'gemini'])],
            'script_id' => ['sometimes', 'nullable', 'uuid'],
        ];
    }
}
