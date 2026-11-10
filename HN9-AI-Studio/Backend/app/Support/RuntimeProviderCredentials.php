<?php

declare(strict_types=1);

namespace App\Support;

use App\AI\Config\PlatformConfig;
use App\Models\AiProvider;
use App\Models\ProviderSetting;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Fills empty runtime config from encrypted provider_settings.
 * A non-empty value already in config (from .env) is left as it is.
 */
final class RuntimeProviderCredentials
{
    /**
     * @var array<string, array{secret: string, paths: list<string>, enable: string|null}>
     */
    public const MAP = [
        'openai' => [
            'secret' => 'api_key',
            'paths' => ['ai.providers.openai.api_key'],
            'enable' => 'ai.providers.openai.enabled',
        ],
        'gemini' => [
            'secret' => 'api_key',
            'paths' => ['ai.providers.gemini.api_key'],
            'enable' => 'ai.providers.gemini.enabled',
        ],
        'elevenlabs' => [
            'secret' => 'api_key',
            'paths' => ['ai.providers.elevenlabs.api_key'],
            'enable' => 'ai.providers.elevenlabs.enabled',
        ],
        'runway' => [
            'secret' => 'api_key',
            'paths' => ['story_video.live_providers.runway.api_key'],
            'enable' => null,
        ],
        'luma' => [
            'secret' => 'api_key',
            'paths' => ['story_video.live_providers.luma.api_key'],
            'enable' => null,
        ],
        'seedance' => [
            'secret' => 'api_key',
            'paths' => ['story_video.live_providers.seedance.api_key'],
            'enable' => null,
        ],
    ];

    /**
     * @var array<string, array<string, string>>
     */
    public const SETTINGS = [
        'openai' => [
            'default_model' => 'ai.providers.openai.default_model',
            'image_default_model' => 'ai.providers.openai.image_default_model',
        ],
        'gemini' => [
            'default_model' => 'ai.providers.gemini.default_model',
        ],
        'elevenlabs' => [
            'default_voice' => 'ai.providers.elevenlabs.default_voice',
            'default_model' => 'ai.providers.elevenlabs.default_model',
        ],
        'runway' => [
            'model' => 'story_video.live_providers.runway.model',
        ],
        'luma' => [
            'model' => 'story_video.live_providers.luma.model',
        ],
        'seedance' => [
            'model' => 'story_video.live_providers.seedance.model',
        ],
    ];

    public static ?bool $platformResolvedBeforeApply = null;

    /** @var array<string, mixed> */
    private array $originals = [];

    public function shouldApply(): bool
    {
        if (app()->runningUnitTests()) {
            return config('hn9.runtime_credentials.enabled') === true;
        }

        return true;
    }

    public function noteOrdering(): void
    {
        if (self::$platformResolvedBeforeApply !== null) {
            return;
        }

        self::$platformResolvedBeforeApply = app()->bound(PlatformConfig::class) && app()->resolved(PlatformConfig::class);
    }

    public function apply(): void
    {
        $this->noteOrdering();
        if (! $this->shouldApply()) {
            return;
        }

        try {
            if (! Schema::hasTable('ai_providers') || ! Schema::hasTable('provider_settings')) {
                return;
            }

            $this->resetToOriginals();
            $environment = (string) config('app.env');
            $providers = AiProvider::query()->whereIn('slug', array_keys(self::MAP))->get()->keyBy('slug');

            foreach (self::MAP as $slug => $map) {
                $provider = $providers->get($slug);
                if (! $provider instanceof AiProvider) {
                    continue;
                }

                $settings = ProviderSetting::query()
                    ->where('ai_provider_id', $provider->id)
                    ->where('environment', $environment)
                    ->get();

                foreach ($settings as $setting) {
                    $value = $this->readValue($setting, $slug);
                    if ($value === null) {
                        continue;
                    }
                    if ($setting->is_secret && $setting->key === $map['secret']) {
                        $this->overlaySecret($map, $provider, $value);
                    } elseif (! $setting->is_secret && isset(self::SETTINGS[$slug][$setting->key])) {
                        $this->overlayPlain(self::SETTINGS[$slug][$setting->key], $value);
                    }
                }
            }
        } catch (QueryException) {
            Log::warning('Provider credential overlay skipped because the database is not ready.');
        } catch (Throwable) {
            Log::warning('Provider credential overlay skipped.');
        }
    }

    public function original(string $path): mixed
    {
        if (! array_key_exists($path, $this->originals)) {
            $this->originals[$path] = config($path);
        }

        return $this->originals[$path];
    }

    /**
     * @param  array{secret: string, paths: list<string>, enable: string|null}  $map
     */
    private function overlaySecret(array $map, AiProvider $provider, string $value): void
    {
        $path = $map['paths'][0];
        $current = $this->original($path);
        if (is_string($current) && trim($current) !== '') {
            return;
        }

        config([$path => $value]);
        if ($map['enable'] !== null && $provider->status === 'active') {
            config([$map['enable'] => true]);
        }
    }

    private function overlayPlain(string $path, string $value): void
    {
        $current = $this->original($path);
        if (is_string($current) && trim($current) !== '') {
            return;
        }

        config([$path => $value]);
    }

    private function resetToOriginals(): void
    {
        foreach (self::MAP as $map) {
            foreach ($map['paths'] as $path) {
                config([$path => $this->original($path)]);
            }
            if ($map['enable'] !== null) {
                config([$map['enable'] => $this->original($map['enable'])]);
            }
        }
        foreach (self::SETTINGS as $settings) {
            foreach ($settings as $path) {
                config([$path => $this->original($path)]);
            }
        }
    }

    private function readValue(ProviderSetting $setting, string $slug): ?string
    {
        try {
            $value = $setting->value;
        } catch (DecryptException) {
            Log::warning('A stored provider credential could not be read.', [
                'slug' => $slug,
                'key' => $setting->key,
            ]);

            return null;
        }

        return is_string($value) && trim($value) !== '' ? $value : null;
    }
}
