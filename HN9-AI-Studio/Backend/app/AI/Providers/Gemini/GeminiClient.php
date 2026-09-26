<?php

declare(strict_types=1);

namespace App\AI\Providers\Gemini;

use App\AI\Exceptions\ProviderApiException;
use App\AI\Exceptions\ProviderNetworkException;
use App\AI\Exceptions\ProviderTimeoutException;
use App\AI\Http\AbstractProviderClient;
use App\AI\Support\ProviderErrorSanitizer;
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

    /**
     * `POST /{version}/models/{model}:predictLongRunning` — Veo video start.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function predictLongRunning(string $model, array $payload): array
    {
        return $this->postJson($this->method($model, 'predictLongRunning'), $payload);
    }

    /**
     * `GET /{version}/{operation}` — poll a Veo long-running operation.
     *
     * @return array<string, mixed>
     */
    public function operation(string $name): array
    {
        return $this->getJson(ltrim($name, '/'));
    }

    /**
     * Download a completed Veo file. The vendor URI is a full HTTPS URL.
     * The same API key used to start the operation is sent as the header and,
     * when the URI does not already include one, as the `key` query parameter.
     * The authenticated URL is never logged.
     */
    public function download(string $uri): string
    {
        try {
            $response = $this->dispatch(
                fn ($request) => $request->timeout(max($this->timeout, 120))->withHeaders($this->headers())->get($this->withDownloadKey($uri)),
            );
        } catch (ProviderTimeoutException) {
            throw ProviderTimeoutException::forProvider($this->providerKey);
        } catch (ProviderNetworkException) {
            throw ProviderNetworkException::forProvider($this->providerKey, new \RuntimeException('Gemini video download failed.'));
        }

        if ($response->failed()) {
            throw $this->downloadFailure($response);
        }

        $body = $response->body();

        if ($body === '') {
            throw ProviderApiException::forProvider(
                $this->providerKey,
                'Gemini returned an empty video download.',
            );
        }

        return $body;
    }

    /**
     * Add the configured API key as `key` only when the URI does not already have one.
     * Existing query parameters are preserved. A present key is not replaced or repeated.
     */
    private function withDownloadKey(string $uri): string
    {
        $parts = parse_url($uri);

        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host']) || ! in_array($parts['scheme'], ['https', 'http'], true)) {
            throw ProviderApiException::forProvider($this->providerKey, 'Gemini returned a video URI that could not be downloaded.');
        }

        $query = [];

        if (isset($parts['query']) && is_string($parts['query']) && $parts['query'] !== '') {
            parse_str($parts['query'], $query);
        }

        $existing = $query['key'] ?? null;

        if (! is_string($existing) || $existing === '') {
            $query['key'] = $this->config->apiKey;
        }

        $port = isset($parts['port']) ? ':'.$parts['port'] : '';
        $path = $parts['path'] ?? '';
        $built = $parts['scheme'].'://'.$parts['host'].$port.$path;

        if ($query !== []) {
            $built .= '?'.http_build_query($query);
        }

        if (isset($parts['fragment']) && is_string($parts['fragment']) && $parts['fragment'] !== '') {
            $built .= '#'.$parts['fragment'];
        }

        return $built;
    }

    /**
     * A failed download keeps the HTTP status and the sanitized Gemini error.
     * The API key, request URL, and headers are not included.
     */
    private function downloadFailure(Response $response): ProviderApiException
    {
        $json = $response->json();
        $body = is_array($json) ? $json : null;
        $httpStatus = $response->status();
        $providerStatus = $body['error']['status'] ?? null;
        $providerStatus = is_string($providerStatus) && $providerStatus !== ''
            ? $this->redactSecret($providerStatus)
            : null;
        $vendorMessage = $this->errorMessage($body);
        $vendorMessage = is_string($vendorMessage) ? $this->redactSecret($vendorMessage) : null;

        $message = 'Gemini video download failed (HTTP '.$httpStatus;

        if ($providerStatus !== null) {
            $message .= ', '.$providerStatus;
        }

        $message .= ')';

        if ($vendorMessage !== null && $vendorMessage !== '') {
            $message .= ': '.$vendorMessage;
        }

        return ProviderApiException::forProvider(
            $this->providerKey,
            $this->redactSecret($message),
            $httpStatus >= 400 && $httpStatus <= 599 ? $httpStatus : 502,
            array_filter([
                'http_status' => $httpStatus,
                'provider_status' => $providerStatus,
            ], static fn (mixed $value): bool => $value !== null && $value !== ''),
        );
    }

    private function redactSecret(string $text): string
    {
        $key = $this->config->apiKey;
        $text = str_replace([$key, rawurlencode($key)], '[redacted]', $text);
        $text = (string) preg_replace('#https?://\S+#i', '[redacted]', $text);

        return ProviderErrorSanitizer::message($text, 'Gemini video download failed.');
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
