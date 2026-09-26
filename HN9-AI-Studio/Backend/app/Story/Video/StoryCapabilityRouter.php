<?php

declare(strict_types=1);

namespace App\Story\Video;

use App\Story\Contracts\StoryCapabilityRouterInterface;
use App\Story\Contracts\StoryVideoProviderAdapterInterface;
use App\Story\Enums\StoryVideoCapability;
use App\Story\Enums\StoryVideoErrorCode;

final class StoryCapabilityRouter implements StoryCapabilityRouterInterface
{
    /**
     * @var list<StoryVideoProviderAdapterInterface>
     */
    private array $adapters = [];

    public function register(StoryVideoProviderAdapterInterface $adapter): void
    {
        $this->adapters[] = $adapter;
    }

    public function adapters(): array
    {
        return $this->adapters;
    }

    public function adaptersFor(StoryVideoCapability $capability): array
    {
        return array_values(array_filter(
            $this->adapters,
            static fn (StoryVideoProviderAdapterInterface $adapter): bool => $adapter->enabled()
                && $adapter->supports($capability),
        ));
    }

    public function route(StoryVideoCapability $capability): StoryCapabilityRoute
    {
        $matches = $this->adaptersFor($capability);
        $available = [];
        $durations = [];
        $ratios = [];
        $resolutions = [];
        $inputs = [];
        $keys = [];
        $mins = [];
        $maxs = [];
        $audio = false;
        $polling = false;
        $webhook = false;
        $download = false;
        $async = false;

        foreach ($matches as $adapter) {
            $keys[] = $adapter->key();
            $durations = [...$durations, ...$adapter->supportedDurations($capability)];
            $ratios = [...$ratios, ...$adapter->supportedAspectRatios($capability)];
            $resolutions = [...$resolutions, ...$adapter->supportedResolutions($capability)];
            $inputs = [...$inputs, ...$adapter->supportedInputTypes($capability)];
            if ($adapter->minDurationSeconds($capability) !== null) {
                $mins[] = $adapter->minDurationSeconds($capability);
            }
            if ($adapter->maxDurationSeconds($capability) !== null) {
                $maxs[] = $adapter->maxDurationSeconds($capability);
            }
            $audio = $audio || $adapter->audioSupported($capability);
            $polling = $polling || $adapter->supportsPolling($capability);
            $webhook = $webhook || $adapter->supportsWebhook($capability);
            $download = $download || $adapter->supportsDownload($capability);
            $async = $async || $adapter->asyncMode($capability)->value !== 'sync';

            if ($adapter->isAvailable($capability)) {
                $available[] = $adapter;
            }
        }

        return new StoryCapabilityRoute(
            capability: $capability,
            available: $available !== [],
            durations: array_values(array_unique($durations)),
            minDurationSeconds: $mins === [] ? null : min($mins),
            maxDurationSeconds: $maxs === [] ? null : max($maxs),
            aspectRatios: array_values(array_unique($ratios)),
            resolutions: array_values(array_unique($resolutions)),
            inputTypes: array_values(array_unique($inputs)),
            audioSupported: $audio,
            asyncSupported: $async || $polling || $webhook,
            pollingSupported: $polling,
            webhookSupported: $webhook,
            downloadSupported: $download,
            adapterKeys: array_values(array_unique($keys)),
        );
    }

    public function catalog(): array
    {
        return array_map(
            fn (StoryVideoCapability $capability): StoryCapabilityRoute => $this->route($capability),
            StoryVideoCapability::cases(),
        );
    }

    public function resolve(StoryVideoGenerationRequest $request): StoryVideoRoutingDecision
    {
        $capability = $request->capability;
        $candidates = $this->adaptersFor($capability);

        usort(
            $candidates,
            static fn (StoryVideoProviderAdapterInterface $a, StoryVideoProviderAdapterInterface $b): int => $b->priority() <=> $a->priority(),
        );

        $eligible = [];
        $reasons = [];

        foreach ($candidates as $adapter) {
            $reject = $this->rejectionReason($adapter, $request);
            if ($reject !== null) {
                $reasons[] = [
                    'provider' => $adapter->key(),
                    'rejected' => true,
                    'reason' => $reject,
                ];
                continue;
            }

            $eligible[] = $adapter;
            $reasons[] = [
                'provider' => $adapter->key(),
                'rejected' => false,
                'priority' => $adapter->priority(),
            ];
        }

        if ($eligible === []) {
            return new StoryVideoRoutingDecision(
                capability: $capability,
                matched: false,
                reasons: $reasons,
                errorCode: StoryVideoErrorCode::CapabilityNotAvailable->value,
                errorMessage: 'No eligible video provider can satisfy this request.',
            );
        }

        $selected = $eligible[0];
        if ($request->preferredProvider !== null) {
            foreach ($eligible as $adapter) {
                if ($adapter->key() === $request->preferredProvider) {
                    $selected = $adapter;
                    break;
                }
            }
        }

        $modelKey = $this->selectModel($selected, $request);
        $fallbacks = array_values(array_map(
            static fn (StoryVideoProviderAdapterInterface $adapter): string => $adapter->key(),
            array_slice($eligible, 1),
        ));

        return new StoryVideoRoutingDecision(
            capability: $capability,
            matched: true,
            providerKey: $selected->key(),
            modelKey: $modelKey,
            priority: $selected->priority(),
            asyncMode: $selected->asyncMode($capability),
            fallbackProviders: $fallbacks,
            reasons: $reasons,
        );
    }

    private function rejectionReason(
        StoryVideoProviderAdapterInterface $adapter,
        StoryVideoGenerationRequest $request,
    ): ?string {
        $capability = $request->capability;

        if (! $adapter->isAvailable($capability)) {
            return 'provider_unavailable';
        }

        if ($request->preferredProvider !== null
            && $request->preferredProvider === $adapter->key()
            && ! $adapter->isAvailable($capability)
        ) {
            return 'preferred_provider_unavailable';
        }

        if ($request->durationSeconds !== null) {
            $durations = $adapter->supportedDurations($capability);
            $min = $adapter->minDurationSeconds($capability);
            $max = $adapter->maxDurationSeconds($capability);
            $seconds = $request->durationSeconds;
            $inList = $durations !== [] && in_array($seconds, $durations, true);
            $inRange = $durations === []
                && ($min === null || $seconds >= $min)
                && ($max === null || $seconds <= $max);
            if (! $inList && ! $inRange && $durations !== []) {
                return 'duration_unsupported';
            }
            if ($durations === [] && ! $inRange && ($min !== null || $max !== null)) {
                return 'duration_unsupported';
            }
            if ($durations !== [] && ! $inList) {
                return 'duration_unsupported';
            }
        }

        if ($request->aspectRatio !== null) {
            $ratios = $adapter->supportedAspectRatios($capability);
            if ($ratios !== [] && ! in_array($request->aspectRatio, $ratios, true)) {
                return 'aspect_ratio_unsupported';
            }
        }

        if ($request->resolution !== null) {
            $resolutions = $adapter->supportedResolutions($capability);
            if ($resolutions !== [] && ! in_array($request->resolution, $resolutions, true)) {
                return 'resolution_unsupported';
            }
        }

        if ($request->audioRequested && ! $adapter->audioSupported($capability)) {
            return 'audio_unsupported';
        }

        if ($capability === StoryVideoCapability::Audio) {
            $role = $request->metadata['audio_role'] ?? null;
            $roles = $adapter->supportedAudioRoles($capability);
            if (! is_string($role) || $role === '' || ($roles !== [] && ! in_array($role, $roles, true))) {
                return 'audio_role_unsupported';
            }
        }

        if ($request->inputs !== []) {
            $supported = $adapter->supportedInputTypes($capability);
            foreach ($request->inputs as $input) {
                if ($supported !== [] && ! in_array($input->type->value, $supported, true)) {
                    return 'input_type_unsupported';
                }
            }
        } elseif ($request->prompt !== null) {
            $supported = $adapter->supportedInputTypes($capability);
            if ($supported !== [] && ! in_array('text', $supported, true)) {
                return 'input_type_unsupported';
            }
        }

        if ($request->preferredModel !== null) {
            $models = $adapter->models();
            $found = false;
            foreach ($models as $model) {
                if ($model->modelKey === $request->preferredModel && $model->enabled) {
                    $found = true;
                    break;
                }
            }
            if ($models !== [] && ! $found) {
                return 'model_unavailable';
            }
        }

        return null;
    }

    private function selectModel(
        StoryVideoProviderAdapterInterface $adapter,
        StoryVideoGenerationRequest $request,
    ): ?string {
        $models = array_values(array_filter(
            $adapter->models(),
            static fn (StoryVideoModelSpec $model): bool => $model->enabled
                && in_array($request->capability, $model->capabilities, true),
        ));

        usort(
            $models,
            static fn (StoryVideoModelSpec $a, StoryVideoModelSpec $b): int => $b->priority <=> $a->priority,
        );

        if ($request->preferredModel !== null) {
            foreach ($models as $model) {
                if ($model->modelKey === $request->preferredModel) {
                    return $model->modelKey;
                }
            }
        }

        return $models[0]->modelKey ?? null;
    }
}
