<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class DashboardUsageRequest extends FormRequest
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
            'from' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'project' => ['sometimes', 'nullable', 'uuid'],
            'provider' => ['sometimes', 'nullable', 'string', 'max:64'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            $from = $this->input('from');
            $to = $this->input('to');

            if (is_string($from) && is_string($to) && $from !== '' && $to !== '' && $from > $to) {
                $validator->errors()->add('from', 'The from date must be before or equal to the to date.');
            }
        });
    }
}
