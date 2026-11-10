<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ApplyStoryVoiceTimingRequest extends FormRequest
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
            'scene_uuids' => ['sometimes', 'array'],
            'scene_uuids.*' => ['string', 'uuid'],
            'lead_in_ms' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:3000'],
            'tail_ms' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:3000'],
            'accept_regeneration' => ['sometimes', 'boolean'],
            'idempotency_key' => ['sometimes', 'nullable', 'string', 'max:100'],
        ];
    }
}
