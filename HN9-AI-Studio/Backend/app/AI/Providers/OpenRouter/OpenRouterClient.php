<?php

declare(strict_types=1);

namespace App\AI\Providers\OpenRouter;

use App\AI\Exceptions\AIException;
use App\AI\Exceptions\ProviderApiException;
use App\AI\Exceptions\ProviderAuthenticationException;
use App\AI\Exceptions\ProviderRateLimitException;
use App\AI\Http\AbstractProviderClient;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Response;

/**
 * Transport for the OpenRouter REST API. Adds the vendor's bearer credential,
 * its optional attribution headers and its routes to the shared client; base
 * URL, timeout, retry and typed error mapping are inherited.
 *
 * Two vendor specifics are handled through the base class's seams:
 *
 * - OpenRouter reserves HTTP 403 for moderation blocks rather than credential
 *   failures, so the authentication test is narrowed to 401.
 * - An upstream failure can be delivered inside a 2xx envelope, so a decoded
 *   body carrying an `error` object is treated as the failure it describes.
 */
final readonly class OpenRouterClient extends AbstractProviderClient
{
    public function __construct(Factory $http, private OpenRouterConfig $config)
    {
        parent::__construct($http, OpenRouterConfig::KEY, 'OpenRouter', $config->baseUrl, $config->timeout, $config->maxRetries);
    }

    /**
     * `POST /chat/completions` — text generation across every routed vendor.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function chatCompletions(array $payload): array
    {
        return $this->postJson('chat/completions', $payload);
    }

    /**
     * `GET /key` — credential metadata. Used as the health probe because it
     * authenticates without billing a generation.
     *
     * @return array<string, mixed>
     */
    public function key(): array
    {
        return $this->getJson('key');
    }

    /**
     * `GET /models/{author}/{slug}/endpoints` — catalogue metadata for one
     * model, used to verify that the configured model is actually routable.
     *
     * @return array<string, mixed>
     */
    public function modelEndpoints(string $author, string $slug): array
    {
        return $this->getJson('models/'.rawurlencode($author).'/'.rawurlencode($slug).'/endpoints');
    }

    protected function headers(): array
    {
        return [...$this->config->requestHeaders(), 'Authorization' => 'Bearer '.$this->config->apiKey];
    }

    /**
     * @return array<string, mixed>
     */
    protected function decode(Response $response): array
    {
        $body = parent::decode($response);

        // A routed request can succeed at the transport while the upstream call
        // failed; the embedded error is authoritative in that case.
        if (is_array($body['error'] ?? null)) {
            throw $this->embeddedFailure($body);
        }

        return $body;
    }

    /**
     * OpenRouter answers 403 for content moderation, not for credentials, so a
     * blocked prompt must not be reported as an authentication failure.
     *
     * @param  array<string, mixed>|null  $body
     */
    protected function isAuthenticationFailure(Response $response, ?array $body): bool
    {
        return $response->status() === 401 || ($body['error']['code'] ?? null) === 401;
    }

    /**
     * Map an error carried inside a successful response onto the typed exception
     * its own status code describes.
     *
     * @param  array<string, mixed>  $body
     */
    private function embeddedFailure(array $body): AIException
    {
        $status = $this->errorStatus($body);

        return match (true) {
            $status === 401 => ProviderAuthenticationException::forProvider($this->providerKey),
            $status === 429 => ProviderRateLimitException::forProvider($this->providerKey),
            default => ProviderApiException::forProvider(
                $this->providerKey,
                $this->errorMessage($body) ?? "{$this->providerLabel} API request failed.",
                $status ?? 502,
            ),
        };
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function errorStatus(array $body): ?int
    {
        $code = $body['error']['code'] ?? null;

        if (is_string($code) && ctype_digit($code)) {
            $code = (int) $code;
        }

        return is_int($code) && $code >= 400 && $code <= 599 ? $code : null;
    }
}
