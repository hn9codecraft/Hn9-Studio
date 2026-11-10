<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Services\ProviderCredentialService;
use App\Support\RuntimeProviderCredentials;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateProviderCredentialRequest extends FormRequest
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
            'api_key' => ['sometimes', 'nullable', 'string', 'max:512'],
            'settings' => ['sometimes', 'array'],
            'settings.*' => ['nullable', 'string', 'max:200'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $slug = (string) $this->route('slug');
            if (! isset(RuntimeProviderCredentials::MAP[$slug])) {
                $validator->errors()->add('slug', 'This provider cannot be managed here.');

                return;
            }

            $settings = $this->input('settings', []);
            if (is_array($settings)) {
                foreach (array_keys($settings) as $key) {
                    if (! isset(RuntimeProviderCredentials::SETTINGS[$slug][$key])) {
                        $validator->errors()->add('settings.'.$key, 'That setting is not available for this provider.');
                    }
                }
            }

            if (! $this->exists('api_key') && ! is_array($settings)) {
                $validator->errors()->add('api_key', 'Enter an API key or a setting.');

                return;
            }

            if ($this->exists('api_key')) {
                $apiKey = $this->input('api_key');
                if (! is_string($apiKey) || trim($apiKey) === '') {
                    $validator->errors()->add('api_key', 'Enter an API key.');

                    return;
                }
                try {
                    app(ProviderCredentialService::class)->assertKey($apiKey);
                } catch (\InvalidArgumentException $exception) {
                    $validator->errors()->add('api_key', $exception->getMessage());
                }
            }
        });
    }
}
