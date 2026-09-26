<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateStoryReelRequest extends FormRequest
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
            'title' => ['sometimes', 'required', 'string', 'max:200'],
            'description' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'status' => ['sometimes', 'string', 'in:draft,active'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $payload = [];
        if ($this->has('title') && is_string($this->input('title'))) {
            $payload['title'] = trim($this->input('title'));
        }
        if ($this->has('description') && is_string($this->input('description'))) {
            $description = trim($this->input('description'));
            $payload['description'] = $description === '' ? null : $description;
        }
        if ($payload !== []) {
            $this->merge($payload);
        }
    }
}
