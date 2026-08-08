<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateProviderSettingRequest extends FormRequest
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
            'ai_provider_id' => ['sometimes', 'integer', 'exists:ai_providers,id'],
            'key' => ['sometimes', 'string', 'max:100'],
            'value' => ['sometimes', 'nullable', 'string'],
            'is_secret' => ['sometimes', 'boolean'],
            'environment' => ['sometimes', 'string', 'max:100'],
        ];
    }
}
