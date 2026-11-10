<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class PreviewStoryVoiceTimingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'round_to' => ['sometimes', 'nullable', 'integer', 'in:1,5'],
            'lead_in_ms' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:3000'],
            'tail_ms' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:3000'],
        ];
    }
}
