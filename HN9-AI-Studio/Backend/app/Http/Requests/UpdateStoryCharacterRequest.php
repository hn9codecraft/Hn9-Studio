<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateStoryCharacterRequest extends FormRequest
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
            'name' => ['sometimes', 'required', 'string', 'max:120'],
            'short_description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'age' => ['sometimes', 'nullable', 'string', 'max:40'],
            'gender_presentation' => ['sometimes', 'nullable', 'string', 'max:80'],
            'appearance' => ['sometimes', 'nullable', 'string', 'max:4000'],
            'face_description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'hair' => ['sometimes', 'nullable', 'string', 'max:500'],
            'clothing' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'personality' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'voice_description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'special_details' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'sort_order' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:9999'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $trimKeys = [
            'name', 'short_description', 'age', 'gender_presentation', 'appearance',
            'face_description', 'hair', 'clothing', 'personality', 'voice_description', 'special_details',
        ];

        $payload = [];
        foreach ($trimKeys as $key) {
            if ($this->has($key) && is_string($this->input($key))) {
                $payload[$key] = trim($this->input($key));
            }
        }

        if ($payload !== []) {
            $this->merge($payload);
        }
    }
}
