<?php

declare(strict_types=1);

namespace App\AI\Providers\Claude;

use App\AI\Exceptions\ProviderApiException;
use App\AI\Exceptions\ProviderAuthenticationException;
use App\AI\Exceptions\ProviderNetworkException;
use App\AI\Exceptions\ProviderRateLimitException;
use App\AI\Exceptions\ProviderTimeoutException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Response;

final readonly class ClaudeClient
{
    public function __construct(private Factory $http, private ClaudeConfig $config) {}

    /** @param array<string, mixed> $payload @return array<string, mixed> */
    public function messages(array $payload): array
    {
        try {
            $response = $this->http->baseUrl($this->config->baseUrl)->withHeaders(['x-api-key' => $this->config->apiKey, 'anthropic-version' => $this->config->version])->acceptJson()->timeout($this->config->timeout)->retry($this->config->maxRetries, 100, throw: false)->post('messages', $payload);
        } catch (ConnectionException $exception) {
            if (str_contains(strtolower($exception->getMessage()), 'timed out')) {
                throw ProviderTimeoutException::forProvider('claude', $exception);
            }
            throw ProviderNetworkException::forProvider('claude', $exception);
        }

        return $this->decode($response);
    }

    /** @return array<string, mixed> */
    private function decode(Response $response): array
    {
        if (in_array($response->status(), [401, 403], true)) {
            throw ProviderAuthenticationException::forProvider('claude');
        }
        if ($response->status() === 429) {
            throw ProviderRateLimitException::forProvider('claude');
        }
        $json = $response->json();
        if ($response->failed()) {
            throw ProviderApiException::forProvider('claude', is_array($json) && is_string($json['error']['message'] ?? null) ? $json['error']['message'] : 'Claude API request failed.', $response->status());
        }
        if (! is_array($json)) {
            throw ProviderApiException::forProvider('claude', 'Claude returned an invalid JSON response.');
        }

        return $json;
    }
}
