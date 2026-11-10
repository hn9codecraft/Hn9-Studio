<?php

declare(strict_types=1);

namespace App\Services;

use App\AI\Cache\ProviderInstanceCache;
use App\AI\Cache\ProviderMetadataCache;
use App\AI\Config\PlatformConfig;
use App\AI\Contracts\ProviderRegistryInterface;
use App\Contracts\Logging\ActivityLoggerInterface;
use App\Models\AiProvider;
use App\Models\ProviderSetting;
use App\Models\User;
use App\Providers\AIServiceProvider;
use App\Story\Contracts\StoryCapabilityRouterInterface;
use App\Story\Contracts\StoryVideoEngineInterface;
use App\Support\RuntimeProviderCredentials;
use Database\Seeders\AiProviderSeeder;
use Illuminate\Support\Carbon;

final class ProviderCredentialService
{
    public function __construct(
        private RuntimeProviderCredentials $credentials,
        private ActivityLoggerInterface $activity,
    ) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function status(): array
    {
        $rows = [];
        foreach (array_keys(RuntimeProviderCredentials::MAP) as $slug) {
            $rows[] = $this->statusFor($slug);
        }

        return $rows;
    }

    /**
     * @param  array<string, string|null>  $settings
     * @return array<string, mixed>
     */
    public function setKey(string $slug, ?string $apiKey, array $settings, ?User $user): array
    {
        $this->assertSlug($slug);
        $provider = $this->provider($slug);
        $environment = (string) config('app.env');

        if ($apiKey !== null) {
            $this->assertKey($apiKey);
            ProviderSetting::query()->updateOrCreate(
                [
                    'ai_provider_id' => $provider->id,
                    'key' => 'api_key',
                    'environment' => $environment,
                ],
                [
                    'value' => $apiKey,
                    'is_secret' => true,
                ],
            );
            $this->activity->log('provider.credential_updated', $provider, $user, 'API key updated', [
                'slug' => $slug,
            ]);
        }

        foreach ($settings as $key => $value) {
            if (! isset(RuntimeProviderCredentials::SETTINGS[$slug][$key])) {
                continue;
            }
            $text = is_string($value) ? trim($value) : '';
            if ($text === '') {
                ProviderSetting::query()
                    ->where('ai_provider_id', $provider->id)
                    ->where('key', $key)
                    ->where('environment', $environment)
                    ->where('is_secret', false)
                    ->delete();

                continue;
            }
            ProviderSetting::query()->updateOrCreate(
                [
                    'ai_provider_id' => $provider->id,
                    'key' => $key,
                    'environment' => $environment,
                ],
                [
                    'value' => $text,
                    'is_secret' => false,
                ],
            );
            $this->activity->log('provider.setting_updated', $provider, $user, 'Setting ['.$key.'] updated', [
                'slug' => $slug,
                'key' => $key,
            ]);
        }

        $this->refreshRuntime();

        return $this->statusFor($slug);
    }

    /**
     * @return array<string, mixed>
     */
    public function removeKey(string $slug, ?User $user): array
    {
        $this->assertSlug($slug);
        $provider = AiProvider::query()->where('slug', $slug)->first();
        if ($provider instanceof AiProvider) {
            ProviderSetting::query()
                ->where('ai_provider_id', $provider->id)
                ->where('key', 'api_key')
                ->where('environment', (string) config('app.env'))
                ->where('is_secret', true)
                ->delete();
            $this->activity->log('provider.credential_removed', $provider, $user, 'API key removed', [
                'slug' => $slug,
            ]);
        }

        $this->refreshRuntime();

        return $this->statusFor($slug);
    }

    /**
     * @return array<string, mixed>
     */
    public function statusFor(string $slug): array
    {
        $this->assertSlug($slug);
        $map = RuntimeProviderCredentials::MAP[$slug];
        $provider = AiProvider::query()->where('slug', $slug)->first();
        $environment = (string) config('app.env');
        $stored = null;
        $updatedAt = null;
        $plain = [];
        if ($provider instanceof AiProvider) {
            $rows = ProviderSetting::query()
                ->where('ai_provider_id', $provider->id)
                ->where('environment', $environment)
                ->get();
            foreach ($rows as $row) {
                if ($row->is_secret && $row->key === $map['secret']) {
                    $stored = $this->safeValue($row);
                    $updatedAt = $row->updated_at;
                } elseif (! $row->is_secret) {
                    $plain[$row->key] = $this->safeValue($row);
                }
            }
        }

        $envValue = $this->credentials->original($map['paths'][0]);
        $envKey = is_string($envValue) && trim($envValue) !== '' ? $envValue : null;
        $source = $envKey !== null ? 'env' : ($stored !== null ? 'stored' : 'none');
        $effective = $envKey ?? $stored;
        $metadata = $provider instanceof AiProvider && is_array($provider->metadata) ? $provider->metadata : [];

        $settings = [];
        foreach (RuntimeProviderCredentials::SETTINGS[$slug] as $key => $path) {
            $fromEnv = $this->credentials->original($path);
            $value = is_string($fromEnv) && trim($fromEnv) !== '' ? $fromEnv : ($plain[$key] ?? null);
            $settings[] = [
                'key' => $key,
                'value' => is_string($value) && $value !== '' ? $value : null,
            ];
        }

        return [
            'slug' => $slug,
            'name' => $provider?->name ?? ucfirst($slug),
            'category' => $provider?->category ?? 'other',
            'source' => $source,
            'configured' => $effective !== null,
            'masked' => self::mask($effective),
            'editable' => $source !== 'env',
            'updated_at' => $updatedAt instanceof Carbon ? $updatedAt->toIso8601String() : null,
            'last_tested_at' => isset($metadata['last_tested_at']) && is_string($metadata['last_tested_at']) ? $metadata['last_tested_at'] : null,
            'last_test_result' => isset($metadata['last_test_result']) && is_string($metadata['last_test_result']) ? $metadata['last_test_result'] : null,
            'settings' => $settings,
        ];
    }

    public static function mask(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (strlen($value) < 8) {
            return '••••';
        }

        return '••••'.substr($value, -4);
    }

    public function refreshRuntime(): void
    {
        config(['hn9.runtime_credentials.enabled' => true]);
        $this->credentials->apply();

        foreach ([
            PlatformConfig::class,
            ProviderInstanceCache::class,
            ProviderMetadataCache::class,
            ProviderRegistryInterface::class,
            StoryCapabilityRouterInterface::class,
            StoryVideoEngineInterface::class,
        ] as $abstract) {
            if (app()->resolved($abstract)) {
                app()->forgetInstance($abstract);
            }
        }

        $provider = app()->getProvider(AIServiceProvider::class);
        if ($provider instanceof AIServiceProvider) {
            $provider->boot();
        }
    }

    private function provider(string $slug): AiProvider
    {
        $provider = app(AiProviderSeeder::class)->ensure($slug);
        if (! $provider instanceof AiProvider) {
            throw new \InvalidArgumentException('Unknown provider.');
        }

        return $provider;
    }

    private function assertSlug(string $slug): void
    {
        if (! isset(RuntimeProviderCredentials::MAP[$slug])) {
            throw new \InvalidArgumentException('Unknown provider.');
        }
    }

    public function assertKey(string $value): void
    {
        $value = trim($value);
        if ($value === '') {
            throw new \InvalidArgumentException('Enter an API key.');
        }
        if (strlen($value) > 512) {
            throw new \InvalidArgumentException('That API key is too long.');
        }
        if (preg_match('/\s/', $value) === 1) {
            throw new \InvalidArgumentException('An API key cannot contain spaces.');
        }
        if (preg_match('/^[*•]+$/u', $value) === 1 || preg_match('/^[•*]{4,}/u', $value) === 1) {
            throw new \InvalidArgumentException('Paste the key from the provider, not the hidden version.');
        }
    }

    private function safeValue(ProviderSetting $setting): ?string
    {
        try {
            $value = $setting->value;
        } catch (\Illuminate\Contracts\Encryption\DecryptException) {
            return null;
        }

        return is_string($value) && $value !== '' ? $value : null;
    }
}
