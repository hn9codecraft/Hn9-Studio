<?php

declare(strict_types=1);

namespace App\Story\Video;

use App\Story\Enums\StoryVideoAsyncMode;
use App\Story\Enums\StoryVideoCapability;

/**
 * Builds catalog adapters from configuration without vendor hard-coding.
 */
final class StoryVideoCatalogFactory
{
    /**
     * @return list<CatalogStoryVideoAdapter>
     */
    public function fromConfig(array $providers): array
    {
        $adapters = [];

        foreach ($providers as $provider) {
            if (! is_array($provider) || ! isset($provider['key']) || ! is_string($provider['key'])) {
                continue;
            }

            $capabilities = [];
            foreach ($provider['capabilities'] ?? [] as $value) {
                $case = StoryVideoCapability::tryFrom((string) $value);
                if ($case !== null) {
                    $capabilities[] = $case;
                }
            }

            $models = [];
            foreach ($provider['models'] ?? [] as $model) {
                if (! is_array($model) || ! isset($model['key'])) {
                    continue;
                }
                $modelCapabilities = [];
                foreach ($model['capabilities'] ?? $provider['capabilities'] ?? [] as $value) {
                    $case = StoryVideoCapability::tryFrom((string) $value);
                    if ($case !== null) {
                        $modelCapabilities[] = $case;
                    }
                }
                $models[] = new StoryVideoModelSpec(
                    providerKey: $provider['key'],
                    modelKey: (string) $model['key'],
                    displayName: (string) ($model['label'] ?? $model['key']),
                    capabilities: $modelCapabilities,
                    enabled: (bool) ($model['enabled'] ?? true),
                    priority: (int) ($model['priority'] ?? ($provider['priority'] ?? 100)),
                    durations: array_map('intval', $model['durations'] ?? $provider['durations'] ?? []),
                    aspectRatios: array_map('strval', $model['aspect_ratios'] ?? $provider['aspect_ratios'] ?? []),
                    resolutions: array_map('strval', $model['resolutions'] ?? $provider['resolutions'] ?? []),
                    inputTypes: array_map('strval', $model['input_types'] ?? $provider['input_types'] ?? []),
                    audioSupported: (bool) ($model['audio'] ?? $provider['audio'] ?? false),
                );
            }

            $mode = StoryVideoAsyncMode::tryFrom((string) ($provider['async_mode'] ?? 'async_poll'))
                ?? StoryVideoAsyncMode::AsyncPoll;

            $adapters[] = new CatalogStoryVideoAdapter(
                adapterKey: $provider['key'],
                label: (string) ($provider['label'] ?? $provider['key']),
                capabilities: $capabilities,
                enabled: (bool) ($provider['enabled'] ?? true),
                available: (bool) ($provider['available'] ?? false),
                priority: (int) ($provider['priority'] ?? 100),
                durations: array_map('intval', $provider['durations'] ?? []),
                minDuration: isset($provider['min_duration']) ? (int) $provider['min_duration'] : null,
                maxDuration: isset($provider['max_duration']) ? (int) $provider['max_duration'] : null,
                aspectRatios: array_map('strval', $provider['aspect_ratios'] ?? []),
                resolutions: array_map('strval', $provider['resolutions'] ?? []),
                inputTypes: array_map('strval', $provider['input_types'] ?? []),
                audio: (bool) ($provider['audio'] ?? false),
                mode: $mode,
                polling: (bool) ($provider['polling'] ?? false),
                webhook: (bool) ($provider['webhook'] ?? false),
                download: (bool) ($provider['download'] ?? false),
                models: $models,
            );
        }

        return $adapters;
    }
}
