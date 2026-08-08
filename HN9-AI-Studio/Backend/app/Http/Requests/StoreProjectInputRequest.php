<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreProjectInputRequest extends FormRequest
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
            'deliverable_type' => ['required', 'string', 'max:100'],
            'platform' => ['sometimes', 'nullable', 'string', 'max:100'],
            'language' => ['sometimes', 'string', 'max:10'],
            'topic' => ['sometimes', 'nullable', 'string', 'max:255'],
            'goal' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'payload' => ['sometimes', 'array'],
            'source' => ['sometimes', 'string', 'max:50'],
            'type' => ['sometimes', 'string', 'max:50'],
        ];
    }
}
