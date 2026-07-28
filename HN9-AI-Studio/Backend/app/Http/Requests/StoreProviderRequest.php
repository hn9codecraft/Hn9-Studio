<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\ProviderType;
use App\Enums\Status;
use App\Rules\EnumValue;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates input for registering an AI provider. Provider management is
 * privileged; the provider policy enforces admin-only access at the controller.
 */
class StoreProviderRequest extends FormRequest
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
            'slug' => ['required', 'string', 'max:100', 'alpha_dash', 'unique:ai_providers,slug'],
            'name' => ['required', 'string', 'max:255'],
            'category' => ['required', 'string', new EnumValue(ProviderType::class)],
            'status' => ['sometimes', 'string', new EnumValue(Status::class)],
            'base_url' => ['sometimes', 'nullable', 'url', 'max:255'],
            'priority' => ['sometimes', 'integer', 'min:0'],
            'capabilities' => ['sometimes', 'array'],
            'metadata' => ['sometimes', 'array'],
        ];
    }
}
