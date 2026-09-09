<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Support\DashboardActionRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class DashboardActionsRequest extends FormRequest
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
            'module' => ['sometimes', 'nullable', 'string', Rule::in(DashboardActionRules::MODULES)],
            'status' => ['sometimes', 'nullable', 'string', Rule::in(DashboardActionRules::STATUSES)],
            'project' => ['sometimes', 'nullable', 'uuid'],
        ];
    }
}
