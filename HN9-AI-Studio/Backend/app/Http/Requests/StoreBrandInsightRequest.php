<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class StoreBrandInsightRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'focus' => ['sometimes', 'string'],
            'depth' => ['sometimes', 'integer', 'min:1', 'max:5'],
        ];
    }
}
