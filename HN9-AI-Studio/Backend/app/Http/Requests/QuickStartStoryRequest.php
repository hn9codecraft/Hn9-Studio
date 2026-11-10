<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Story\Enums\StoryBibleAspectRatio;
use App\Story\Support\StoryTemplateCatalog;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class QuickStartStoryRequest extends FormRequest
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
            'template' => ['required', 'string', Rule::in(array_keys(config('story_templates', [])))],
            'topic' => ['required', 'string', 'min:3', 'max:2000'],
            'duration_seconds' => ['required', 'integer', Rule::in(StoryTemplateCatalog::DURATIONS)],
            'aspect_ratio' => ['sometimes', 'nullable', 'string', Rule::in(StoryBibleAspectRatio::values())],
            'language' => ['sometimes', 'nullable', 'string', Rule::in(StoryTemplateCatalog::LANGUAGES)],
            'tone' => ['sometimes', 'nullable', 'string', 'max:100'],
            'style' => ['sometimes', 'nullable', 'string', 'max:255'],
            'audience' => ['sometimes', 'nullable', 'string', 'max:255'],
            'characters' => ['sometimes', 'nullable', 'array', 'max:4'],
            'characters.*.name' => ['required', 'string', 'max:120'],
            'characters.*.short_description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'fields' => ['sometimes', 'array'],
            'fields.*' => ['nullable', 'string'],
            'overwrite' => ['sometimes', 'boolean'],
            'idempotency_key' => ['sometimes', 'nullable', 'string', 'max:100'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $key = $this->input('template');
            $templates = config('story_templates', []);
            if (! is_string($key) || ! isset($templates[$key]) || ! is_array($templates[$key])) {
                return;
            }
            $allowed = [];
            foreach ($templates[$key]['fields'] ?? [] as $field) {
                if (is_array($field) && is_string($field['key'] ?? null)) {
                    $allowed[$field['key']] = $field;
                }
            }
            $fields = $this->input('fields', []);
            if (! is_array($fields)) {
                return;
            }
            foreach ($fields as $name => $value) {
                if (! isset($allowed[$name])) {
                    $validator->errors()->add('fields.'.$name, 'This field is not part of the template.');
                    continue;
                }
                if (is_string($value) && mb_strlen($value) > (int) ($allowed[$name]['max'] ?? 0)) {
                    $validator->errors()->add('fields.'.$name, 'This field is too long.');
                }
            }
            foreach ($allowed as $name => $field) {
                if (empty($field['required'])) {
                    continue;
                }
                $value = $fields[$name] ?? null;
                if (! is_string($value) || trim($value) === '') {
                    $validator->errors()->add('fields.'.$name, 'This field is required.');
                }
            }
        });
    }
}
