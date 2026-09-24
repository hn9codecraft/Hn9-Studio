<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\ScriptStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreScriptRequest extends FormRequest
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
            'title' => ['required', 'string', 'max:255'],
            'body' => ['sometimes', 'nullable', 'string', 'max:200000'],
            'status' => ['sometimes', 'string', Rule::in(ScriptStatus::assignableValues())],
        ];
    }
}
