<?php

declare(strict_types=1);

namespace App\Story\Video;

use App\AI\Contracts\CircuitBreakerInterface;
use App\Story\Contracts\LiveStoryVideoProviderAdapterInterface;
use App\Story\Contracts\StoryCapabilityRouterInterface;
use App\Story\Contracts\StoryVideoProviderAdapterInterface;
use App\Story\Enums\StoryVideoErrorCode;
use App\Story\Exceptions\StoryVideoEngineException;

/**
 * Chooses one configured video provider for one generation request.
 *
 * The decision is made before a job exists. After a provider accepts an
 * operation, this class is not asked again. It never submits work and never
 * calls a second provider for an attempt that already has an operation id.
 */
final readonly class StoryVideoProviderOrchestrator
{
    public function __construct(
        private StoryCapabilityRouterInterface $router,
        private CircuitBreakerInterface $circuits,
    ) {}

    public function route(StoryVideoGenerationRequest $request): StoryVideoRoutingDecision
    {
        $evaluated = [];
        foreach ($this->router->adapters() as $adapter) {
            if (! $adapter instanceof LiveStoryVideoProviderAdapterInterface || $adapter->vendor() === 'elevenlabs') {
                continue;
            }
            $evaluated[] = $this->evaluate($adapter, $request);
        }

        $production = array_values(array_filter(
            $evaluated,
            static fn (array $row): bool => ($row['reason'] ?? '') !== 'excluded_from_production_routing',
        ));
        if ($production === []) {
            return $this->unmatched(
                $request,
                $evaluated,
                StoryVideoErrorCode::GenerationNotEnabled->value,
                'Video generation is not configured yet.',
            );
        }

        if (! $this->fallbackEnabled()) {
            $first = $this->preferredOrder()[0] ?? null;
            foreach ($production as $index => $row) {
                if (($row['eligible'] ?? false) === true && $row['provider'] !== $first) {
                    $production[$index]['eligible'] = false;
                    $production[$index]['reason'] = 'fallback_disabled';
                }
            }
        }

        $eligible = $this->routable($production);
        if ($eligible === []) {
            return $this->unmatched(
                $request,
                $production,
                $this->failureCode($production),
                $this->failureMessage($production),
            );
        }

        usort($eligible, function (array $left, array $right): int {
            return [$this->rank((string) $left['provider']), -((int) $left['priority']), (string) $left['provider']]
                <=> [$this->rank((string) $right['provider']), -((int) $right['priority']), (string) $right['provider']];
        });

        $selected = $eligible[0];
        $preferred = $this->preferredOrder()[0] ?? null;
        $reason = $preferred !== null && $selected['provider'] === $preferred
            ? 'preferred_provider'
            : 'fallback_to_next_eligible';

        return new StoryVideoRoutingDecision(
            capability: $request->capability,
            matched: true,
            providerKey: (string) $selected['provider'],
            modelKey: isset($selected['model']) ? (string) $selected['model'] : null,
            priority: (int) $selected['priority'],
            asyncMode: $selected['adapter'] instanceof StoryVideoProviderAdapterInterface
                ? $selected['adapter']->asyncMode($request->capability)
                : null,
            fallbackProviders: array_values(array_map(
                static fn (array $row): string => (string) $row['provider'],
                array_slice($eligible, 1),
            )),
            reasons: $this->publicReasons($production, (string) $selected['provider'], $reason),
        );
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    public function snapshot(StoryVideoGenerationRequest $request, StoryVideoRoutingDecision $decision): array
    {
        return [
            'policy_version' => (string) config('story_video.routing.policy_version', 'm11.18.4'),
            'capability' => $request->capability->value,
            'requested_duration_seconds' => $request->durationSeconds,
            'selected_provider' => $decision->providerKey,
            'selected_model' => $decision->modelKey,
            'reason' => $this->selectedReason($decision),
            'candidates' => $decision->reasons,
            'selected_at' => now()->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function evaluate(LiveStoryVideoProviderAdapterInterface $adapter, StoryVideoGenerationRequest $request): array
    {
        $base = [
            'provider' => $adapter->key(),
            'model' => null,
            'priority' => $adapter->priority(),
            'eligible' => false,
            'adapter' => $adapter,
        ];
        if ($this->excluded($adapter)) {
            return [...$base, 'reason' => 'excluded_from_production_routing'];
        }

        $reason = $this->exclusion($adapter, $request);
        if ($reason !== null) {
            return [...$base, 'reason' => $reason];
        }

        $model = $this->model($adapter, $request);
        if ($model === null) {
            return [...$base, 'reason' => 'model_unavailable'];
        }

        try {
            $adapter->validate($request);
        } catch (StoryVideoEngineException) {
            return [...$base, 'reason' => 'rejected_before_acceptance'];
        }

        return [...$base, 'eligible' => true, 'model' => $model, 'reason' => 'eligible'];
    }

    private function exclusion(LiveStoryVideoProviderAdapterInterface $adapter, StoryVideoGenerationRequest $request): ?string
    {
        $capability = $request->capability;
        if (! $adapter->enabled()) {
            return 'disabled';
        }
        if (! $adapter->supports($capability)) {
            return 'capability_unsupported';
        }
        if (! $adapter->isAvailable($capability)) {
            return 'provider_unavailable';
        }
        if (! $this->circuits->allows($adapter->key())) {
            return 'circuit_open';
        }

        $seconds = $request->durationSeconds;
        if ($seconds !== null) {
            $durations = $adapter->supportedDurations($capability);
            $listed = $durations !== [] && in_array($seconds, $durations, true);
            $ranged = $durations === []
                && ($adapter->minDurationSeconds($capability) === null || $seconds >= $adapter->minDurationSeconds($capability))
                && ($adapter->maxDurationSeconds($capability) === null || $seconds <= $adapter->maxDurationSeconds($capability));
            if (! $listed && ! $ranged) {
                return 'unsupported_duration';
            }
        }

        if ($request->aspectRatio !== null) {
            $ratios = $adapter->supportedAspectRatios($capability);
            if ($ratios !== [] && ! in_array($request->aspectRatio, $ratios, true)) {
                return 'aspect_ratio_unsupported';
            }
        }

        if ($request->inputs !== []) {
            $supported = $adapter->supportedInputTypes($capability);
            foreach ($request->inputs as $input) {
                if ($supported !== [] && ! in_array($input->type->value, $supported, true)) {
                    return 'input_type_unsupported';
                }
            }
        }

        return null;
    }

    private function model(LiveStoryVideoProviderAdapterInterface $adapter, StoryVideoGenerationRequest $request): ?string
    {
        $models = array_values(array_filter(
            $adapter->models(),
            function (StoryVideoModelSpec $model) use ($request): bool {
                if (! $model->enabled || ! in_array($request->capability, $model->capabilities, true)) {
                    return false;
                }
                if ($request->durationSeconds !== null && $model->durations !== [] && ! in_array($request->durationSeconds, $model->durations, true)) {
                    return false;
                }
                if ($request->aspectRatio !== null && $model->aspectRatios !== [] && ! in_array($request->aspectRatio, $model->aspectRatios, true)) {
                    return false;
                }

                return true;
            },
        ));
        usort($models, static fn (StoryVideoModelSpec $left, StoryVideoModelSpec $right): int => $right->priority <=> $left->priority);

        return $models[0]->modelKey ?? null;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function routable(array $rows): array
    {
        return array_values(array_filter(
            $rows,
            static fn (array $row): bool => ($row['eligible'] ?? false) === true,
        ));
    }

    private function excluded(LiveStoryVideoProviderAdapterInterface $adapter): bool
    {
        return $adapter->vendor() === 'gemini' || $adapter->key() === 'video.live';
    }

    private function rank(string $key): int
    {
        $order = $this->preferredOrder();
        $index = array_search($key, $order, true);

        return $index === false ? count($order) + 1 : (int) $index;
    }

    /**
     * @return list<string>
     */
    private function preferredOrder(): array
    {
        $order = config('story_video.routing.preferred_order', ['video.runway', 'video.luma', 'video.seedance']);

        return is_array($order) ? array_values(array_filter($order, 'is_string')) : [];
    }

    private function fallbackEnabled(): bool
    {
        return filter_var(config('story_video.routing.fallback_enabled', true), FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function publicReasons(array $rows, string $selected, string $selectedReason): array
    {
        $reasons = [];
        foreach ($rows as $row) {
            if (($row['reason'] ?? '') === 'excluded_from_production_routing') {
                continue;
            }
            $provider = (string) $row['provider'];
            $reasons[] = [
                'provider' => $provider,
                'model' => $row['model'] ?? null,
                'eligible' => ($row['eligible'] ?? false) === true,
                'reason' => $provider === $selected ? $selectedReason : (string) $row['reason'],
            ];
        }

        return $reasons;
    }

    private function selectedReason(StoryVideoRoutingDecision $decision): ?string
    {
        foreach ($decision->reasons as $reason) {
            if (is_array($reason) && ($reason['provider'] ?? null) === $decision->providerKey) {
                return isset($reason['reason']) ? (string) $reason['reason'] : null;
            }
        }

        return null;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function failureCode(array $rows): string
    {
        $reasons = array_map(static fn (array $row): string => (string) ($row['reason'] ?? ''), $rows);
        if ($reasons !== [] && array_unique($reasons) === ['input_type_unsupported']) {
            return StoryVideoErrorCode::InvalidInput->value;
        }
        if ($reasons !== [] && array_unique($reasons) === ['aspect_ratio_unsupported']) {
            return StoryVideoErrorCode::InvalidInput->value;
        }

        return StoryVideoErrorCode::CapabilityNotAvailable->value;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function failureMessage(array $rows): string
    {
        $reasons = array_map(static fn (array $row): string => (string) ($row['reason'] ?? ''), $this->considered($rows));
        if ($reasons !== [] && count(array_diff($reasons, ['unsupported_duration'])) === 0) {
            return 'None of the connected video services supports this clip length.';
        }
        if ($reasons !== [] && count(array_diff($reasons, ['input_type_unsupported', 'capability_unsupported'])) === 0) {
            return "This video setup can't use the selected reference.";
        }
        if ($reasons !== [] && count(array_diff($reasons, ['aspect_ratio_unsupported'])) === 0) {
            return 'The picture shape is not supported.';
        }

        return 'Video generation is not available for this scene right now.';
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function considered(array $rows): array
    {
        return array_values(array_filter(
            $rows,
            static fn (array $row): bool => ($row['reason'] ?? '') !== 'excluded_from_production_routing',
        ));
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function unmatched(
        StoryVideoGenerationRequest $request,
        array $rows,
        string $code,
        string $message,
    ): StoryVideoRoutingDecision {
        return new StoryVideoRoutingDecision(
            capability: $request->capability,
            matched: false,
            reasons: $this->publicReasons($rows, '', ''),
            errorCode: $code,
            errorMessage: $message,
        );
    }
}
