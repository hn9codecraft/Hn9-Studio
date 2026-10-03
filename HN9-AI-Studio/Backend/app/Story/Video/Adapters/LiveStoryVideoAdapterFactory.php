<?php

declare(strict_types=1);

namespace App\Story\Video\Adapters;

use App\Services\VideoBinaryStore;
use App\Story\Enums\StoryVideoAsyncMode;
use App\Story\Enums\StoryVideoCapability;
use App\Story\Video\CatalogStoryVideoAdapter;
use App\Story\Video\StoryVideoModelSpec;

/**
 * Builds the credentialed HTTP video adapters from configuration. Capability
 * tables follow each vendor's published limits.
 */
final readonly class LiveStoryVideoAdapterFactory
{
    public function __construct(private VideoBinaryStore $binaries) {}

    /**
     * @param  array<string, array<string, mixed>>  $providers
     * @return list<HttpStoryVideoAdapter>
     */
    public function fromConfig(array $providers, bool $testing): array
    {
        $adapters = [];
        foreach ($providers as $vendor => $config) {
            if (! is_array($config) || ! self::ready($config, $testing)) {
                continue;
            }

            $adapter = match ($vendor) {
                SeedanceStoryVideoAdapter::VENDOR => new SeedanceStoryVideoAdapter($this->seedanceCatalog($config), $this->binaries, $config),
                RunwayStoryVideoAdapter::VENDOR => new RunwayStoryVideoAdapter($this->runwayCatalog($config), $this->binaries, $config),
                LumaStoryVideoAdapter::VENDOR => new LumaStoryVideoAdapter($this->lumaCatalog($config), $this->binaries, $config),
                default => null,
            };
            if ($adapter !== null) {
                $adapters[] = $adapter;
            }
        }

        return $adapters;
    }

    /**
     * @param  array<string, mixed>  $config
     */
    public static function ready(array $config, bool $testing): bool
    {
        $key = $config['api_key'] ?? null;
        if (! is_string($key) || trim($key) === '') {
            return false;
        }

        $flag = $config['enabled'] ?? null;
        if ($flag === null || $flag === '') {
            return ! $testing;
        }

        return filter_var($flag, FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function seedanceCatalog(array $config): CatalogStoryVideoAdapter
    {
        $model = (string) ($config['model'] ?? 'dreamina-seedance-2-0-260128');
        // Seedance 2.0 standard renders up to 1080p and 4k; fast, mini and 2.5 stop at 720p. 2.5 runs to 30 seconds.
        $standard = str_starts_with($model, 'dreamina-seedance-2-0-') && ! str_contains($model, 'fast') && ! str_contains($model, 'mini');
        $resolutions = $standard ? ['480p', '720p', '1080p', '4k'] : ['480p', '720p'];
        $max = str_starts_with($model, 'dreamina-seedance-2-5') ? 30 : 15;
        $durations = range(4, $max);
        $capabilities = [
            StoryVideoCapability::TextToVideo,
            StoryVideoCapability::ImageToVideo,
            StoryVideoCapability::ReferenceToVideo,
        ];
        if ((bool) ($config['signed_reference_videos'] ?? false)) {
            $capabilities[] = StoryVideoCapability::VideoEdit;
            $capabilities[] = StoryVideoCapability::VideoExtend;
        }
        $webhook = is_string($config['callback_base_url'] ?? null)
            && str_starts_with((string) $config['callback_base_url'], 'https://');

        return $this->catalog(
            $config,
            $capabilities,
            $durations,
            ['16:9', '4:3', '1:1', '3:4', '9:16', '21:9'],
            $resolutions,
            ['text', 'image', 'reference_image', 'video'],
            true,
            $webhook,
            $model,
        );
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function runwayCatalog(array $config): CatalogStoryVideoAdapter
    {
        return $this->catalog(
            $config,
            [StoryVideoCapability::TextToVideo, StoryVideoCapability::ImageToVideo, StoryVideoCapability::VideoEdit],
            range(2, 10),
            ['16:9', '9:16'],
            ['720p'],
            ['text', 'image', 'reference_image', 'video'],
            false,
            false,
            (string) ($config['model'] ?? 'gen4.5'),
        );
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function lumaCatalog(array $config): CatalogStoryVideoAdapter
    {
        return $this->catalog(
            $config,
            [
                StoryVideoCapability::TextToVideo,
                StoryVideoCapability::ImageToVideo,
                StoryVideoCapability::VideoEdit,
                StoryVideoCapability::VideoExtend,
            ],
            [5, 10],
            ['9:16', '3:4', '1:1', '4:3', '16:9', '21:9'],
            ['360p', '540p', '720p', '1080p'],
            ['text', 'image', 'reference_image', 'video'],
            false,
            false,
            (string) ($config['model'] ?? 'ray-3.2'),
        );
    }

    /**
     * @param  array<string, mixed>  $config
     * @param  list<StoryVideoCapability>  $capabilities
     * @param  list<int>  $durations
     * @param  list<string>  $ratios
     * @param  list<string>  $resolutions
     * @param  list<string>  $inputs
     */
    private function catalog(
        array $config,
        array $capabilities,
        array $durations,
        array $ratios,
        array $resolutions,
        array $inputs,
        bool $audio,
        bool $webhook,
        string $model,
    ): CatalogStoryVideoAdapter {
        $key = (string) $config['key'];
        $priority = (int) ($config['priority'] ?? 100);

        return new CatalogStoryVideoAdapter(
            adapterKey: $key,
            label: (string) ($config['label'] ?? 'Video service'),
            capabilities: $capabilities,
            enabled: true,
            available: true,
            priority: $priority,
            durations: $durations,
            minDuration: min($durations),
            maxDuration: max($durations),
            aspectRatios: $ratios,
            resolutions: $resolutions,
            inputTypes: $inputs,
            audio: $audio,
            mode: $webhook ? StoryVideoAsyncMode::AsyncWebhook : StoryVideoAsyncMode::AsyncPoll,
            polling: true,
            webhook: $webhook,
            download: true,
            models: [
                new StoryVideoModelSpec(
                    providerKey: $key,
                    modelKey: $model,
                    displayName: $model,
                    capabilities: $capabilities,
                    enabled: true,
                    priority: $priority,
                    durations: $durations,
                    aspectRatios: $ratios,
                    resolutions: $resolutions,
                    inputTypes: $inputs,
                    audioSupported: $audio,
                ),
            ],
        );
    }
}
