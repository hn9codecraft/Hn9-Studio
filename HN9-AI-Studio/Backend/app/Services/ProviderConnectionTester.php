<?php

declare(strict_types=1);

namespace App\Services;

use App\AI\Support\ProviderErrorSanitizer;
use App\Models\AiProvider;
use App\Models\User;
use App\Support\RuntimeProviderCredentials;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

final class ProviderConnectionTester
{
    public function __construct(
        private ProviderCredentialService $credentials,
        private ProviderRegistryService $registry,
    ) {}

    /**
     * @return array{slug: string, result: string, message: string, supported: bool}
     */
    public function test(string $slug, ?User $user = null): array
    {
        if (! isset(RuntimeProviderCredentials::MAP[$slug])) {
            return [
                'slug' => $slug,
                'result' => 'unsupported',
                'message' => 'This provider cannot be tested here.',
                'supported' => false,
            ];
        }

        $spec = $this->requestSpec($slug);
        if ($spec === null) {
            $stored = $this->store($slug, 'not_verifiable', $user);

            return [
                'slug' => $slug,
                'result' => 'not_verifiable',
                'message' => $stored['message'],
                'supported' => true,
            ];
        }

        $key = $this->effectiveKey($slug);
        if ($key === null) {
            $stored = $this->store($slug, 'invalid_key', $user);

            return [
                'slug' => $slug,
                'result' => 'invalid_key',
                'message' => ProviderErrorSanitizer::message('No key is saved for this provider.', 'No key is saved for this provider.'),
                'supported' => true,
            ];
        }

        try {
            $headers = [];
            foreach ($spec['headers'] as $name => $value) {
                $headers[$name] = str_replace('{key}', $key, $value);
            }
            $response = Http::connectTimeout(5)->timeout(10)->acceptJson()->withHeaders($headers)->get($spec['url']);
        } catch (ConnectionException) {
            $stored = $this->store($slug, 'unreachable', $user);

            return [
                'slug' => $slug,
                'result' => 'unreachable',
                'message' => $stored['message'],
                'supported' => true,
            ];
        }

        $status = $response->status();
        $result = match (true) {
            $status >= 200 && $status < 300 => 'connected',
            $status === 401 || $status === 403 => 'invalid_key',
            $status === 429 => 'rate_limited',
            default => 'unreachable',
        };
        $stored = $this->store($slug, $result, $user);

        return [
            'slug' => $slug,
            'result' => $result,
            'message' => $stored['message'],
            'supported' => true,
        ];
    }

    /**
     * @return array{url: string, headers: array<string, string>}|null
     */
    public function requestSpec(string $slug): ?array
    {
        return match ($slug) {
            'openai' => [
                'url' => rtrim((string) config('ai.providers.openai.base_url'), '/').'/models',
                'headers' => ['Authorization' => 'Bearer {key}'],
            ],
            'gemini' => [
                'url' => rtrim((string) config('ai.providers.gemini.base_url'), '/').'/'.trim((string) config('ai.providers.gemini.version', 'v1beta'), '/').'/models',
                'headers' => ['x-goog-api-key' => '{key}'],
            ],
            'elevenlabs' => [
                'url' => rtrim((string) config('ai.providers.elevenlabs.base_url'), '/').'/user',
                'headers' => ['xi-api-key' => '{key}'],
            ],
            'runway' => [
                'url' => rtrim((string) config('story_video.live_providers.runway.base_url'), '/').'/v1/organization',
                'headers' => [
                    'Authorization' => 'Bearer {key}',
                    'X-Runway-Version' => (string) config('story_video.live_providers.runway.api_version', '2024-11-06'),
                ],
            ],
            default => null,
        };
    }

    private function effectiveKey(string $slug): ?string
    {
        $path = RuntimeProviderCredentials::MAP[$slug]['paths'][0];
        $value = config($path);

        return is_string($value) && trim($value) !== '' ? $value : null;
    }

    /**
     * @return array{message: string}
     */
    private function store(string $slug, string $result, ?User $user): array
    {
        $message = match ($result) {
            'connected' => 'Connected.',
            'invalid_key' => 'The key was rejected.',
            'rate_limited' => 'The provider asked us to wait.',
            'not_verifiable' => 'Key saved; it will be checked on the first generation.',
            default => 'The provider could not be reached.',
        };
        $message = ProviderErrorSanitizer::message($message, $message);
        $provider = AiProvider::query()->where('slug', $slug)->first();
        if ($provider instanceof AiProvider) {
            $this->registry->markTested($provider, $user, $result);
        }

        return ['message' => $message];
    }
}
