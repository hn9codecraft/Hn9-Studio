<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\VideoAspectRatio;
use App\Enums\VideoDuration;
use App\Enums\VideoStatus;
use App\Rules\EnumValue;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateVideoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->exists('duration')) {
            $this->merge([
                'duration' => is_numeric($this->input('duration')) ? (int) $this->input('duration') : $this->input('duration'),
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'title' => ['sometimes', 'required', 'string', 'max:255'],
            'prompt' => ['sometimes', 'required', 'string', 'max:20000'],
            'negative_prompt' => ['sometimes', 'nullable', 'string', 'max:20000'],
            'aspect_ratio' => ['sometimes', 'string', new EnumValue(VideoAspectRatio::class)],
            'duration' => ['sometimes', 'integer', new EnumValue(VideoDuration::class)],
            'status' => ['sometimes', 'string', Rule::in(VideoStatus::assignableValues())],
        ];
    }
}
