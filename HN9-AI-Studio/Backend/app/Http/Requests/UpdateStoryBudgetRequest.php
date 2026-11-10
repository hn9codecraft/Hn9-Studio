<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateStoryBudgetRequest extends FormRequest
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
            'budget_amount' => ['nullable', 'numeric', 'min:0', 'max:10000000'],
            'budget_currency' => ['sometimes', 'string', Rule::in(['INR', 'USD', 'inr', 'usd'])],
        ];
    }
}
