<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class EstimateStoryCostRequest extends FormRequest
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
            'kind' => ['required', 'string', Rule::in([
                'unit_video',
                'scene_regenerate',
                'scene_edit',
                'scene_extend',
                'video_generate',
                'audio',
                'reference_image',
                'plan_generate',
            ])],
            'plan_uuid' => ['sometimes', 'nullable', 'uuid'],
            'unit_uuid' => ['sometimes', 'nullable', 'uuid'],
            'reel_uuid' => ['sometimes', 'nullable', 'uuid'],
            'scene_uuid' => ['sometimes', 'nullable', 'uuid'],
            'version_uuid' => ['sometimes', 'nullable', 'uuid'],
            'text' => ['sometimes', 'nullable', 'string', 'max:20000'],
            'seconds' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:600'],
            'count' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:8'],
        ];
    }
}
