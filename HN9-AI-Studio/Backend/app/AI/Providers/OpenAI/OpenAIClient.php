<?php

declare(strict_types=1);

namespace App\AI\Providers\OpenAI;

use App\AI\Http\AbstractProviderClient;
use Illuminate\Http\Client\Factory;

final readonly class OpenAIClient extends AbstractProviderClient
{
    public function __construct(Factory $http, private OpenAIConfig $config)
    {
        parent::__construct($http, 'openai', 'OpenAI', $config->baseUrl, $config->timeout, $config->maxRetries);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function post(string $path, array $payload): array
    {
        return $this->postJson($path, $payload);
    }

    /**
     * @return array<string, mixed>
     */
    public function get(string $path): array
    {
        return $this->getJson($path);
    }

    protected function headers(): array
    {
        return ['Authorization' => 'Bearer '.$this->config->apiKey];
    }
}
