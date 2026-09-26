<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Story\Support\StoryPlanDurationCalculator;
use Illuminate\Foundation\Http\FormRequest;

class StoreStoryPlanRequest extends FormRequest
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
            'title' => ['nullable', 'string', 'max:200'],
            'idea' => ['required', 'string', 'max:10000'],
            'requested_duration_seconds' => [
                'required',
                'integer',
                'min:'.StoryPlanDurationCalculator::MIN_SECONDS,
                'max:'.StoryPlanDurationCalculator::MAX_SECONDS,
            ],
        ];
    }

    protected function prepareForValidation(): void
    {
        $payload = [];
        if ($this->has('title') && is_string($this->input('title'))) {
            $title = trim($this->input('title'));
            $payload['title'] = $title === '' ? null : $title;
        }
        if ($this->has('idea') && is_string($this->input('idea'))) {
            $payload['idea'] = trim($this->input('idea'));
        }
        if ($payload !== []) {
            $this->merge($payload);
        }
    }
}
