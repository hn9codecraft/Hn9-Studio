<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\ProviderType;
use App\Enums\Status;
use App\Rules\EnumValue;
use Illuminate\Foundation\Http\FormRequest;

class UpdateProviderRequest extends FormRequest
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
            'slug' => ['sometimes', 'string', 'max:100', 'alpha_dash'],
            'name' => ['sometimes', 'string', 'max:255'],
            'category' => ['sometimes', 'string', new EnumValue(ProviderType::class)],
            'status' => ['sometimes', 'string', new EnumValue(Status::class)],
            'base_url' => ['sometimes', 'nullable', 'url', 'max:255'],
            'priority' => ['sometimes', 'integer', 'min:0'],
            'capabilities' => ['sometimes', 'array'],
            'metadata' => ['sometimes', 'array'],
        ];
    }
}
