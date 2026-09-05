<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\ScriptStatus;
use App\Rules\EnumValue;
use Illuminate\Foundation\Http\FormRequest;

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
            'status' => ['sometimes', 'string', new EnumValue(ScriptStatus::class)],
        ];
    }
}
