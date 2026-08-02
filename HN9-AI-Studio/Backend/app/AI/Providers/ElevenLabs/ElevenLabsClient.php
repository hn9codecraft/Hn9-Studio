<?php

declare(strict_types=1);

namespace App\AI\Providers\ElevenLabs;

use App\AI\Exceptions\AIException;
use App\AI\Exceptions\ProviderApiException;
use App\AI\Http\AbstractProviderClient;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;

/**
 * Transport for the ElevenLabs REST API. Adds the vendor's API-key header and
 * its routes to the shared client; base URL, timeout, retry and the typed
 * timeout/network taxonomy are inherited.
 *
 * Two vendor specifics are handled through the base class's seams:
 *
 * - Synthesis answers with raw audio bytes, not JSON, so {@see self::speech()}
 *   dispatches through the shared transport and decodes the body itself. Every
 *   other route is ordinary JSON.
 * - The vendor reports an exhausted character quota as HTTP 401. Treating that
 *   as a credential failure would send operators rotating a perfectly good key,
 *   so quota statuses are separated from authentication failures.
 */
final readonly class ElevenLabsClient extends AbstractProviderClient
{
    /**
     * The synthesis route answers with audio on success and JSON on failure, so
     * it must not advertise a preference for either.
     */
    private const ANY_CONTENT = '*/*';

    /**
     * Vendor error statuses that mean "out of credits", not "bad credential".
     */
    private const QUOTA_STATUSES = ['quota_exceeded', 'subscription_quota_exceeded'];

    /**
     * HTTP 402 Payment Required — the status a quota failure is reported under
     * once it has been separated from authentication.
     */
    private const QUOTA_STATUS_CODE = 402;

    public function __construct(Factory $http, private ElevenLabsConfig $config)
    {
        parent::__construct($http, ElevenLabsConfig::KEY, 'ElevenLabs', $config->baseUrl, $config->timeout, $config->maxRetries);
    }

    /**
     * `POST /text-to-speech/{voice_id}` — synthesises speech. The response body
     * is the audio itself, so it is returned raw alongside the headers that
     * describe it.
     *
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $query
     * @return array{body: string, content_type: string|null, request_id: string|null}
     */
    public function speech(string $voiceId, array $payload, array $query = []): array
    {
        $response = $this->dispatch(fn (PendingRequest $request): Response => $request
            ->withHeaders(['Accept' => self::ANY_CONTENT])
            ->withQueryParameters($query)
            ->post('text-to-speech/'.rawurlencode($voiceId), $payload));

        if ($response->failed()) {
            $body = $response->json();

            throw $this->failureFor($response, is_array($body) ? $body : null);
        }

        if ($response->body() === '') {
            throw ProviderApiException::forProvider($this->providerKey, 'ElevenLabs returned an empty audio response.');
        }

        return [
            'body' => $response->body(),
            'content_type' => $this->header($response, 'Content-Type'),
            'request_id' => $this->header($response, 'request-id'),
        ];
    }

    /**
     * `GET /user/subscription` — credential and quota metadata. Used as the
     * health probe because it authenticates without synthesising any audio.
     *
     * @return array<string, mixed>
     */
    public function subscription(): array
    {
        return $this->getJson('user/subscription');
    }

    /**
     * `GET /voices/{voice_id}` — voice metadata, used to verify the configured
     * voice still exists on the account.
     *
     * @return array<string, mixed>
     */
    public function voice(string $voiceId): array
    {
        return $this->getJson('voices/'.rawurlencode($voiceId));
    }

    /**
     * `GET /models` — the account's model catalogue, used to verify the
     * configured model. The vendor answers with a bare JSON list, which the
     * shared decoder returns as a list-shaped array.
     *
     * @return array<string, mixed>
     */
    public function models(): array
    {
        return $this->getJson('models');
    }

    protected function headers(): array
    {
        return ['xi-api-key' => $this->config->apiKey];
    }

    /**
     * An exhausted quota arrives as 401 with a quota status; it is a billing
     * failure, not a credential one, and must not read as a bad API key.
     *
     * @param  array<string, mixed>|null  $body
     */
    protected function isAuthenticationFailure(Response $response, ?array $body): bool
    {
        return ! $this->isQuotaFailure($body) && parent::isAuthenticationFailure($response, $body);
    }

    /**
     * @param  array<string, mixed>|null  $body
     */
    protected function failureFor(Response $response, ?array $body): AIException
    {
        if ($this->isQuotaFailure($body)) {
            return ProviderApiException::forProvider(
                $this->providerKey,
                $this->errorMessage($body) ?? 'ElevenLabs character quota exceeded.',
                self::QUOTA_STATUS_CODE,
            );
        }

        return parent::failureFor($response, $body);
    }

    /**
     * ElevenLabs reports failures under `detail`, in three shapes: an object
     * with a message, a bare string, or the validation list its framework emits.
     *
     * @param  array<string, mixed>|null  $body
     */
    protected function errorMessage(?array $body): ?string
    {
        if ($body === null) {
            return null;
        }

        $detail = $body['detail'] ?? null;

        $message = match (true) {
            is_string($detail) => $detail,
            is_array($detail) && is_string($detail['message'] ?? null) => $detail['message'],
            is_array($detail) && is_string($detail[0]['msg'] ?? null) => $detail[0]['msg'],
            default => null,
        };

        return $message !== null && $message !== '' ? $message : parent::errorMessage($body);
    }

    /**
     * @param  array<string, mixed>|null  $body
     */
    private function isQuotaFailure(?array $body): bool
    {
        $status = $body['detail']['status'] ?? null;

        return is_string($status) && in_array($status, self::QUOTA_STATUSES, true);
    }

    private function header(Response $response, string $name): ?string
    {
        $value = $response->header($name);

        return $value === '' ? null : $value;
    }
}
