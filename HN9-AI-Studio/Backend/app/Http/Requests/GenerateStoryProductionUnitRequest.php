<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Story\Enums\StoryVideoCapability;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * User intent only. Duration, prompts and provider choice are loaded on the server.
 */
class GenerateStoryProductionUnitRequest extends FormRequest
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
            'capability' => ['required', 'string', Rule::in([
                StoryVideoCapability::TextToVideo->value,
                StoryVideoCapability::ImageToVideo->value,
                StoryVideoCapability::ReferenceToVideo->value,
            ])],
            'intent' => ['sometimes', 'nullable', 'string', 'regex:/^[A-Za-z0-9_-]{1,64}$/'],
            'instruction' => ['sometimes', 'nullable', 'string', 'max:500'],
            'aspect_ratio' => ['sometimes', 'nullable', 'string', 'max:16'],
            'inputs' => ['sometimes', 'array', 'max:8'],
            'inputs.*.type' => ['required', 'string', 'max:32'],
            'inputs.*.asset_id' => ['required', 'uuid'],
        ];
    }
}
