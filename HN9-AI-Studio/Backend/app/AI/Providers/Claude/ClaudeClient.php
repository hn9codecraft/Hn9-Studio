<?php

declare(strict_types=1);

namespace App\AI\Providers\Claude;

use App\AI\Http\AbstractProviderClient;
use Illuminate\Http\Client\Factory;

final readonly class ClaudeClient extends AbstractProviderClient
{
    public function __construct(Factory $http, private ClaudeConfig $config)
    {
        parent::__construct($http, 'claude', 'Claude', $config->baseUrl, $config->timeout, $config->maxRetries);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function messages(array $payload): array
    {
        return $this->postJson('messages', $payload);
    }

    protected function headers(): array
    {
        return [
            'x-api-key' => $this->config->apiKey,
            'anthropic-version' => $this->config->version,
        ];
    }
}
