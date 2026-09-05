<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\ImageAspectRatio;
use App\Enums\ImageStatus;
use App\Rules\EnumValue;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreImageRequest extends FormRequest
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
            'title' => ['required', 'string', 'max:255'],
            'prompt' => ['required', 'string', 'max:20000'],
            'negative_prompt' => ['sometimes', 'nullable', 'string', 'max:20000'],
            'aspect_ratio' => ['sometimes', 'string', new EnumValue(ImageAspectRatio::class)],
            'status' => ['sometimes', 'string', Rule::in(ImageStatus::assignableValues())],
        ];
    }
}
