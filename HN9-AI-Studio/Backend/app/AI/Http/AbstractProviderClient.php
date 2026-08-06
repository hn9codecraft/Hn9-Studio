<?php

declare(strict_types=1);

namespace App\AI\Http;

use App\AI\Config\PlatformConfig;
use App\AI\Config\TimeoutConfig;
use App\AI\Exceptions\AIException;
use App\AI\Exceptions\ProviderApiException;
use App\AI\Exceptions\ProviderAuthenticationException;
use App\AI\Exceptions\ProviderNetworkException;
use App\AI\Exceptions\ProviderRateLimitException;
use App\AI\Exceptions\ProviderTimeoutException;
use Closure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;

/**
 * Shared HTTP transport for provider adapters: base URL, timeout and retry
 * wiring, JSON decoding and the mapping of every transport/API failure onto the
 * typed exceptions of the AI subsystem.
 *
 * Provider-specific behaviour stays in the subclass through two seams:
 * {@see self::headers()} (authentication) and {@see self::failureFor()} /
 * {@see self::isAuthenticationFailure()} (vendor error taxonomies). This class
 * calls no vendor endpoint of its own — subclasses declare the routes.
 */
abstract readonly class AbstractProviderClient
{
    /**
     * Delay in milliseconds between transport retries.
     */
    protected const RETRY_DELAY_MS = 100;

    public function __construct(
        protected Factory $http,
        protected string $providerKey,
        protected string $providerLabel,
        protected string $baseUrl,
        protected int $timeout,
        protected int $maxRetries,
    ) {}

    /**
     * Authentication (and any vendor-mandated) headers for every request.
     *
     * @return array<string, string>
     */
    abstract protected function headers(): array;

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    protected function postJson(string $path, array $payload = []): array
    {
        return $this->send(fn (PendingRequest $request): Response => $request->post(ltrim($path, '/'), $payload));
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    protected function getJson(string $path, array $query = []): array
    {
        return $this->send(fn (PendingRequest $request): Response => $request->get(ltrim($path, '/'), $query));
    }

    protected function pending(): PendingRequest
    {
        $timeouts = $this->timeouts();

        $request = $this->http->baseUrl($this->baseUrl)
            ->withHeaders($this->headers())
            ->acceptJson()
            ->timeout($timeouts->requestTimeoutFor($this->timeout))
            ->retry($this->maxRetries, self::RETRY_DELAY_MS, throw: false);

        // A separate connection timeout fails a black-holed endpoint in seconds
        // instead of holding the whole request budget open waiting for a socket.
        return $timeouts->connect > 0 ? $request->connectTimeout($timeouts->connect) : $request;
    }

    /**
     * The platform's timeout defaults. A provider's own `timeout` still wins;
     * these fill in the gaps and supply the connection timeout, so every adapter
     * inherits one policy instead of restating it.
     *
     * Read from the {@see PlatformConfig} singleton, so this costs a container
     * lookup rather than re-parsing configuration on every request.
     */
    protected function timeouts(): TimeoutConfig
    {
        return app(PlatformConfig::class)->timeouts;
    }

    /**
     * Decode a vendor response, converting any non-success outcome into a typed
     * exception.
     *
     * @return array<string, mixed>
     */
    protected function decode(Response $response): array
    {
        $json = $response->json();
        $body = is_array($json) ? $json : null;

        if ($response->failed()) {
            throw $this->failureFor($response, $body);
        }

        if ($body === null) {
            throw ProviderApiException::forProvider(
                $this->providerKey,
                "{$this->providerLabel} returned an invalid JSON response.",
            );
        }

        return $body;
    }

    /**
     * Map a failed response onto the matching typed exception. Providers with a
     * richer error taxonomy refine this without touching the transport.
     *
     * @param  array<string, mixed>|null  $body
     */
    protected function failureFor(Response $response, ?array $body): AIException
    {
        if ($this->isAuthenticationFailure($response, $body)) {
            return ProviderAuthenticationException::forProvider($this->providerKey);
        }

        if ($response->status() === 429) {
            return ProviderRateLimitException::forProvider($this->providerKey);
        }

        return ProviderApiException::forProvider(
            $this->providerKey,
            $this->errorMessage($body) ?? "{$this->providerLabel} API request failed.",
            $response->status(),
        );
    }

    /**
     * @param  array<string, mixed>|null  $body
     */
    protected function isAuthenticationFailure(Response $response, ?array $body): bool
    {
        return in_array($response->status(), [401, 403], true);
    }

    /**
     * The vendor-supplied error message, when the payload carries one.
     *
     * @param  array<string, mixed>|null  $body
     */
    protected function errorMessage(?array $body): ?string
    {
        if ($body === null) {
            return null;
        }

        $message = $body['error']['message'] ?? null;

        return is_string($message) && $message !== '' ? $message : null;
    }

    /**
     * Execute a request, converting a transport failure into the matching typed
     * exception. Exposed to subclasses because not every vendor route answers
     * with JSON — a binary payload, for instance, decodes itself but must still
     * inherit this timeout/network taxonomy rather than restating it.
     *
     * @param  Closure(PendingRequest): Response  $call
     */
    protected function dispatch(Closure $call): Response
    {
        try {
            return $call($this->pending());
        } catch (ConnectionException $exception) {
            throw str_contains(strtolower($exception->getMessage()), 'timed out')
                ? ProviderTimeoutException::forProvider($this->providerKey, $exception)
                : ProviderNetworkException::forProvider($this->providerKey, $exception);
        }
    }

    /**
     * @param  Closure(PendingRequest): Response  $call
     * @return array<string, mixed>
     */
    private function send(Closure $call): array
    {
        return $this->decode($this->dispatch($call));
    }
}
