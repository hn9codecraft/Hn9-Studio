<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Story\Enums\StoryAudioRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreateStorySceneAudioRequest extends FormRequest
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
            'role' => ['required', 'string', Rule::in(StoryAudioRole::values())],
            'prompt' => ['nullable', 'string', 'max:10000'],
            'version_id' => ['nullable', 'uuid'],
            'voice' => ['nullable', 'string', 'max:100'],
        ];
    }
}
