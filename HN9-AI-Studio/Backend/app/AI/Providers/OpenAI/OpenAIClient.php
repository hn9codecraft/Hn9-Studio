<?php

declare(strict_types=1);

namespace App\AI\Providers\OpenAI;

use App\AI\Exceptions\ProviderApiException;
use App\AI\Exceptions\ProviderAuthenticationException;
use App\AI\Exceptions\ProviderNetworkException;
use App\AI\Exceptions\ProviderRateLimitException;
use App\AI\Exceptions\ProviderTimeoutException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Response;

final readonly class OpenAIClient
{
    public function __construct(private Factory $http, private OpenAIConfig $config) {}

    /** @param array<string, mixed> $payload @return array<string, mixed> */
    public function post(string $path, array $payload): array
    {
        return $this->request('post', $path, $payload);
    }

    /** @return array<string, mixed> */
    public function get(string $path): array
    {
        return $this->request('get', $path);
    }

    /** @param array<string, mixed> $payload @return array<string, mixed> */
    private function request(string $method, string $path, array $payload = []): array
    {
        try {
            $response = $this->http->baseUrl($this->config->baseUrl)
                ->withToken($this->config->apiKey)
                ->acceptJson()
                ->timeout($this->config->timeout)
                ->retry($this->config->maxRetries, 100, throw: false)
                ->{$method}(ltrim($path, '/'), $payload);
        } catch (ConnectionException $exception) {
            if (str_contains(strtolower($exception->getMessage()), 'timed out')) {
                throw ProviderTimeoutException::forProvider('openai', $exception);
            }
            throw ProviderNetworkException::forProvider('openai', $exception);
        }

        return $this->decode($response);
    }

    /** @return array<string, mixed> */
    private function decode(Response $response): array
    {
        if ($response->status() === 401 || $response->status() === 403) {
            throw ProviderAuthenticationException::forProvider('openai');
        }
        if ($response->status() === 429) {
            throw ProviderRateLimitException::forProvider('openai');
        }
        if ($response->failed()) {
            $json = $response->json();
            $message = is_array($json) && isset($json['error']['message']) && is_string($json['error']['message'])
                ? $json['error']['message'] : 'OpenAI API request failed.';
            throw ProviderApiException::forProvider('openai', $message, $response->status());
        }

        $json = $response->json();
        if (! is_array($json)) {
            throw ProviderApiException::forProvider('openai', 'OpenAI returned an invalid JSON response.');
        }

        return $json;
    }
}
