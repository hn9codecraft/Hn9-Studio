<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Story\Enums\StoryStyleReferenceRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UploadStoryStyleReferenceRequest extends FormRequest
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
            'file' => [
                'required',
                'file',
                'max:10240',
                'mimes:jpg,jpeg,png,webp',
                'mimetypes:image/jpeg,image/png,image/webp',
            ],
            'role' => ['nullable', 'string', Rule::in(StoryStyleReferenceRole::values())],
        ];
    }
}
