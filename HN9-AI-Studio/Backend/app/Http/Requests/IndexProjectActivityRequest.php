<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\ProjectActivityModule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IndexProjectActivityRequest extends FormRequest
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
            'module' => ['sometimes', 'string', Rule::in(ProjectActivityModule::values())],
            'action' => ['sometimes', 'string', 'regex:/^(project|script|image|video|project_asset)\.[a-z_]+$/'],
            'order' => ['sometimes', 'string', Rule::in(['asc', 'desc'])],
            'perPage' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }
}
