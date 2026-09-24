<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class NeedsReworkScriptRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->exists('comment') && is_string($this->input('comment'))) {
            $this->merge([
                'comment' => trim($this->input('comment')),
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'comment' => ['required', 'string', 'min:10', 'max:5000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'comment.required' => 'A rework comment is required so the creator can see what to change.',
            'comment.min' => 'A rework comment must be at least 10 characters.',
        ];
    }
}
