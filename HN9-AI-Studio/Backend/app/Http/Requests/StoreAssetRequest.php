<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\AssetType;
use App\Enums\ExecutionStatus;
use App\Rules\EnumValue;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates input for recording a generated media asset. Authorization is
 * enforced by the asset policy at the controller.
 */
class StoreAssetRequest extends FormRequest
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
            'project_id' => ['required', 'integer', 'exists:projects,id'],
            'type' => ['required', 'string', new EnumValue(AssetType::class)],
            'generated_content_id' => ['sometimes', 'nullable', 'integer', 'exists:generated_contents,id'],
            'workflow_run_id' => ['sometimes', 'nullable', 'integer', 'exists:workflow_runs,id'],
            'agent_execution_id' => ['sometimes', 'nullable', 'integer', 'exists:agent_executions,id'],
            'provider' => ['sometimes', 'nullable', 'string', 'max:100'],
            'status' => ['sometimes', 'string', new EnumValue(ExecutionStatus::class)],
            'prompt' => ['sometimes', 'nullable', 'string'],
            'metadata' => ['sometimes', 'array'],
        ];
    }
}
