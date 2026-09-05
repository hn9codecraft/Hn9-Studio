<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\ProjectAssetSource;
use App\Enums\ProjectAssetStatus;
use App\Enums\ProjectAssetType;
use App\Rules\EnumValue;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateProjectAssetRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        foreach (['file_url', 'mime_type', 'notes'] as $field) {
            if ($this->exists($field) && $this->input($field) === '') {
                $this->merge([$field => null]);
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'title' => ['sometimes', 'required', 'string', 'max:255'],
            'type' => ['sometimes', 'required', 'string', new EnumValue(ProjectAssetType::class)],
            'source' => ['sometimes', 'string', Rule::in(ProjectAssetSource::assignableValues())],
            'status' => ['sometimes', 'string', new EnumValue(ProjectAssetStatus::class)],
            'file_url' => ['sometimes', 'nullable', 'string', 'url', 'max:2048'],
            'mime_type' => ['sometimes', 'nullable', 'string', 'max:255'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:20000'],
        ];
    }
}
