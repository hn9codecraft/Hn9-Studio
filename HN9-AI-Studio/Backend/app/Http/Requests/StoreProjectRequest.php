<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\ProjectStatus;
use App\Rules\EnumValue;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates input for creating a project. Authorization is enforced by the
 * project policy at the controller; this request validates shape only.
 */
class StoreProjectRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['sometimes', 'nullable', 'string', 'max:255', 'alpha_dash'],
            'description' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'type' => ['sometimes', 'nullable', 'string', 'max:100'],
            'status' => ['sometimes', 'string', new EnumValue(ProjectStatus::class)],
            'settings' => ['sometimes', 'array'],
            'metadata' => ['sometimes', 'array'],
        ];
    }
}
