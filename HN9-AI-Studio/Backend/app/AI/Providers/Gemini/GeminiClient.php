<?php

declare(strict_types=1);

namespace App\AI\Providers\Gemini;

use App\AI\Http\AbstractProviderClient;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Response;

/**
 * Transport for the Google Generative Language (Gemini) REST API. Adds the
 * vendor's API-key header and its method-style routes to the shared client;
 * timeout, retry and typed error mapping are inherited.
 */
final readonly class GeminiClient extends AbstractProviderClient
{
    /**
     * Google error statuses that denote a credential problem rather than a
     * malformed request; an invalid key is reported as HTTP 400 by this API.
     */
    private const AUTHENTICATION_STATUSES = ['UNAUTHENTICATED', 'PERMISSION_DENIED'];

    public function __construct(Factory $http, private GeminiConfig $config)
    {
        parent::__construct($http, 'gemini', 'Gemini', $config->endpoint(), $config->timeout, $config->maxRetries);
    }

    /**
     * `POST /{version}/models/{model}:generateContent` — text and image output.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function generateContent(string $model, array $payload): array
    {
        return $this->postJson($this->method($model, 'generateContent'), $payload);
    }

    /**
     * `POST /{version}/models/{model}:countTokens` — the vendor's tokenizer.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function countTokens(string $model, array $payload): array
    {
        return $this->postJson($this->method($model, 'countTokens'), $payload);
    }

    /**
     * `GET /{version}/models/{model}` — model metadata, used as the health probe.
     *
     * @return array<string, mixed>
     */
    public function model(string $model): array
    {
        return $this->getJson('models/'.rawurlencode($model));
    }

    protected function headers(): array
    {
        return ['x-goog-api-key' => $this->config->apiKey];
    }

    /**
     * @param  array<string, mixed>|null  $body
     */
    protected function isAuthenticationFailure(Response $response, ?array $body): bool
    {
        if (parent::isAuthenticationFailure($response, $body)) {
            return true;
        }

        $status = $body['error']['status'] ?? null;

        if (is_string($status) && in_array($status, self::AUTHENTICATION_STATUSES, true)) {
            return true;
        }

        $message = $this->errorMessage($body);

        return $message !== null && str_contains(strtolower($message), 'api key');
    }

    private function method(string $model, string $action): string
    {
        return 'models/'.rawurlencode($model).':'.$action;
    }
}
