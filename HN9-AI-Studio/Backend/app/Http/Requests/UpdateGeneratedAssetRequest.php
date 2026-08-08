<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\ExecutionStatus;
use App\Rules\EnumValue;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates partial updates for generated assets. Authorization is enforced by
 * the controller policy.
 */
class UpdateGeneratedAssetRequest extends FormRequest
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
            'status' => ['sometimes', 'required', 'string', new EnumValue(ExecutionStatus::class)],
            'metadata' => ['sometimes', 'array'],
        ];
    }
}
