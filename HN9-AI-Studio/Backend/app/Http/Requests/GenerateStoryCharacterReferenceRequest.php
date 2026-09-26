<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Story\Enums\StoryCharacterReferenceRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class GenerateStoryCharacterReferenceRequest extends FormRequest
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
            'provider' => ['nullable', 'string', 'max:64'],
            'model' => ['nullable', 'string', 'max:128'],
            'size' => ['nullable', 'string', 'max:32'],
            'quality' => ['nullable', 'string', 'max:32'],
            'role' => ['nullable', 'string', Rule::in(StoryCharacterReferenceRole::values())],
        ];
    }
}
