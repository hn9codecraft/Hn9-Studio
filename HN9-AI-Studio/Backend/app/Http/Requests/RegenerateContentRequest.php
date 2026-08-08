<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Optional overrides for a content re-run. An empty body is valid: the pipeline
 * then reuses the template and variables the original render was recorded with.
 *
 * Authorization is the controller's policy check, not this request's.
 */
class RegenerateContentRequest extends FormRequest
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
            'template_key' => ['sometimes', 'string', 'max:100'],
            'model' => ['sometimes', 'string', 'max:100'],
            'topic' => ['sometimes', 'nullable', 'string', 'max:255'],
            'goal' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'payload' => ['sometimes', 'array'],
            'variables' => ['sometimes', 'array'],
        ];
    }
}
