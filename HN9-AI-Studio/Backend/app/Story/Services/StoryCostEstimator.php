<?php

declare(strict_types=1);

namespace App\Story\Services;

use App\Models\Project;
use App\Story\Enums\StoryVideoCapability;
use App\Story\Exceptions\StoryException;
use App\Story\Models\StoryProductionPlan;
use App\Story\Models\StoryProductionUnit;
use App\Story\Models\StoryScene;
use App\Story\Models\StorySceneVersion;
use App\Story\Models\StoryVideoGenerationJob;
use App\Story\Support\StoryCostEstimate;
use App\Story\Video\StoryVideoGenerationRequest;
use App\Story\Video\StoryVideoProviderOrchestrator;

/**
 * Prices a paid Story action from the rate table. It never calls a provider,
 * never writes a row, and never invents a rate when the table has null.
 */
final readonly class StoryCostEstimator
{
    public function __construct(private StoryVideoProviderOrchestrator $orchestrator) {}

    /**
     * @param  array<string, mixed>  $params
     */
    public function estimate(Project $project, string $kind, array $params = []): StoryCostEstimate
    {
        return match ($kind) {
            'unit_video' => $this->video($project, $kind, StoryVideoCapability::TextToVideo, $this->unitSeconds($project, $params), false),
            'scene_regenerate' => $this->video($project, $kind, StoryVideoCapability::TextToVideo, $this->sceneSeconds($project, $params), false),
            'scene_edit' => $this->video($project, $kind, StoryVideoCapability::VideoEdit, $this->versionSeconds($project, $params), true),
            'scene_extend' => $this->video($project, $kind, StoryVideoCapability::VideoExtend, $this->extendSeconds($params), true),
            'video_generate' => $this->video($project, $kind, StoryVideoCapability::TextToVideo, $this->requestedSeconds($params), false),
            'audio' => $this->audio($params),
            'reference_image' => $this->image($params),
            'plan_generate' => $this->plan($params),
            default => throw StoryException::notFound('Cost estimate'),
        };
    }

    public function fromJobRequest(StoryVideoGenerationRequest $request, string $providerKey, ?string $modelKey, bool $connected): StoryCostEstimate
    {
        $edit = in_array($request->capability, [StoryVideoCapability::VideoEdit, StoryVideoCapability::VideoExtend], true);
        if ($request->capability === StoryVideoCapability::Audio) {
            return $this->price('audio', 'audio.elevenlabs', null, 'characters', mb_strlen((string) $request->prompt), $this->audioRate(), $connected || $this->audioConnected());
        }

        $provider = $providerKey !== '' ? $providerKey : $this->fallbackProvider();
        $model = $edit ? $this->editModel($provider, $modelKey) : ($modelKey ?: $this->generateModel($provider));

        return $this->price(
            $edit ? ($request->capability === StoryVideoCapability::VideoExtend ? 'scene_extend' : 'scene_edit') : 'video_generate',
            $provider,
            $model,
            'seconds',
            (float) max(0, (int) $request->durationSeconds),
            $this->videoRate($provider, (string) $model),
            $connected,
        );
    }

    private function video(Project $project, string $kind, StoryVideoCapability $capability, float $seconds, bool $edit): StoryCostEstimate
    {
        $request = new StoryVideoGenerationRequest(
            capability: $capability,
            durationSeconds: (int) max(1, $seconds),
            prompt: 'estimate',
        );
        $connected = false;
        $provider = $this->fallbackProvider();
        $model = $edit ? $this->editModel($provider, null) : $this->generateModel($provider);

        try {
            $decision = $this->orchestrator->route($request);
            if ($decision->matched && is_string($decision->providerKey) && $decision->providerKey !== '') {
                $connected = true;
                $provider = $decision->providerKey;
                $model = $edit
                    ? $this->editModel($provider, $decision->modelKey)
                    : ($decision->modelKey ?: $this->generateModel($provider));
            }
        } catch (\Throwable) {
            $connected = false;
        }

        return $this->price($kind, $provider, $model, 'seconds', $seconds, $this->videoRate($provider, (string) $model), $connected);
    }

    /**
     * @param  array<string, mixed>  $params
     */
    private function audio(array $params): StoryCostEstimate
    {
        $text = (string) ($params['text'] ?? '');

        return $this->price('audio', 'audio.elevenlabs', null, 'characters', (float) mb_strlen($text), $this->audioRate(), $this->audioConnected());
    }

    /**
     * @param  array<string, mixed>  $params
     */
    private function image(array $params): StoryCostEstimate
    {
        $count = max(1, (int) ($params['count'] ?? 1));
        $rate = config('story_cost.image.default');
        $rate = is_numeric($rate) ? (float) $rate : null;

        return $this->price('reference_image', 'image.default', null, 'images', (float) $count, $rate, false);
    }

    /**
     * @param  array<string, mixed>  $params
     */
    private function plan(array $params): StoryCostEstimate
    {
        $rate = config('story_cost.text.per_1k_tokens');
        $rate = is_numeric($rate) ? (float) $rate : null;
        $tokens = max(1, (int) ceil(mb_strlen((string) ($params['text'] ?? '')) / 4));

        return $this->price('plan_generate', 'text', null, 'tokens', (float) $tokens, $rate, $rate !== null);
    }

    private function price(string $kind, ?string $provider, ?string $model, string $units, float $quantity, ?float $rate, bool $connected): StoryCostEstimate
    {
        $available = $rate !== null;
        $amount = $available ? $this->amount($units, $quantity, (float) $rate) : null;
        $margin = (float) config('story_cost.safety_margin', 0.10);
        $high = $amount === null ? null : round($amount * (1 + $margin), 4);
        $displayCurrency = (string) config('story_cost.display_currency', 'INR');
        $display = $amount === null ? null : $this->display($amount, $displayCurrency);

        return new StoryCostEstimate(
            kind: $kind,
            providerKey: $provider,
            modelKey: $model,
            units: $units,
            quantity: $quantity,
            rateUsd: $rate,
            amountUsd: $amount,
            amountUsdHigh: $high,
            amountDisplay: $display,
            displayCurrency: $displayCurrency,
            available: $available,
            providerConnected: $connected,
        );
    }

    private function amount(string $units, float $quantity, float $rate): float
    {
        $raw = $units === 'characters' ? ($quantity / 1000) * $rate : $quantity * $rate;

        return round($raw, 4);
    }

    private function display(float $usd, string $currency): float
    {
        if (strtoupper($currency) === 'INR') {
            return round($usd * (float) config('story_cost.fx.USD_INR', 94), 2);
        }

        return round($usd, 2);
    }

    private function videoRate(string $provider, string $model): ?float
    {
        $video = config('story_cost.video');
        $table = is_array($video) ? ($video[$provider] ?? null) : null;
        if (! is_array($table) || ! array_key_exists($model, $table) || ! is_numeric($table[$model])) {
            return null;
        }

        return (float) $table[$model];
    }

    private function audioRate(): ?float
    {
        $audio = config('story_cost.audio');
        $rate = is_array($audio) ? ($audio['audio.elevenlabs'] ?? null) : null;

        return is_numeric($rate) ? (float) $rate : null;
    }

    private function audioConnected(): bool
    {
        $key = config('ai.providers.elevenlabs.api_key');

        return is_string($key) && $key !== '';
    }

    private function fallbackProvider(): string
    {
        $order = config('story_video.routing.preferred_order', []);
        $first = is_array($order) ? ($order[0] ?? null) : null;

        return is_string($first) && $first !== '' ? $first : 'video.runway';
    }

    /**
     * @return array<string, mixed>
     */
    private function providerRow(string $providerKey): array
    {
        foreach ((array) config('story_video.live_providers', []) as $row) {
            if (is_array($row) && ($row['key'] ?? null) === $providerKey) {
                return $row;
            }
        }

        return [];
    }

    private function generateModel(string $provider): string
    {
        $model = $this->providerRow($provider)['model'] ?? null;

        return is_string($model) && $model !== '' ? $model : 'gen4.5';
    }

    private function editModel(string $provider, ?string $routed): string
    {
        $model = $this->providerRow($provider)['edit_model'] ?? null;
        if (is_string($model) && $model !== '') {
            return $model;
        }

        return is_string($routed) && $routed !== '' ? $routed : $this->generateModel($provider);
    }

    /**
     * @param  array<string, mixed>  $params
     */
    private function unitSeconds(Project $project, array $params): float
    {
        $planUuid = (string) ($params['plan_uuid'] ?? '');
        $unitUuid = (string) ($params['unit_uuid'] ?? '');
        $plan = StoryProductionPlan::query()
            ->where('uuid', $planUuid)
            ->whereHas('workspace', static fn ($query) => $query->where('project_id', $project->id))
            ->first();
        $unit = $plan === null ? null : StoryProductionUnit::query()
            ->where('uuid', $unitUuid)
            ->whereHas('planScene', static fn ($query) => $query->where('story_production_plan_id', $plan->id))
            ->first();
        if (! $unit instanceof StoryProductionUnit) {
            throw StoryException::notFound('Generation unit');
        }

        return (float) $unit->duration_seconds;
    }

    /**
     * @param  array<string, mixed>  $params
     */
    private function sceneSeconds(Project $project, array $params): float
    {
        $scene = $this->scene($project, $params);

        return (float) $scene->duration_seconds;
    }

    /**
     * @param  array<string, mixed>  $params
     */
    private function versionSeconds(Project $project, array $params): float
    {
        if (isset($params['seconds']) && is_numeric($params['seconds'])) {
            return (float) $params['seconds'];
        }
        $version = $this->version($project, $params);
        $version->loadMissing('scene');
        $job = StoryVideoGenerationJob::query()
            ->where('story_scene_id', $version->story_scene_id)
            ->latest('id')
            ->get()
            ->first(static fn (StoryVideoGenerationJob $job): bool => ($job->provider_metadata['version_id'] ?? null) === $version->uuid);
        $fromJob = is_array($job?->request_payload) ? ($job->request_payload['duration_seconds'] ?? null) : null;
        if (is_numeric($fromJob)) {
            return (float) $fromJob;
        }
        $fromContinuity = is_array($version->continuity) ? ($version->continuity['duration_seconds'] ?? null) : null;
        if (is_numeric($fromContinuity)) {
            return (float) $fromContinuity;
        }

        return (float) ($version->scene?->duration_seconds ?? 0);
    }

    /**
     * @param  array<string, mixed>  $params
     */
    private function extendSeconds(array $params): float
    {
        return (float) ($params['seconds'] ?? 0);
    }

    /**
     * @param  array<string, mixed>  $params
     */
    private function requestedSeconds(array $params): float
    {
        return (float) ($params['seconds'] ?? 0);
    }

    /**
     * @param  array<string, mixed>  $params
     */
    private function scene(Project $project, array $params): StoryScene
    {
        $scene = StoryScene::query()
            ->where('uuid', (string) ($params['scene_uuid'] ?? ''))
            ->whereHas('reel', function ($reel) use ($project, $params): void {
                $reel->where('uuid', (string) ($params['reel_uuid'] ?? ''))
                    ->whereHas('workspace', static fn ($workspace) => $workspace->where('project_id', $project->id));
            })
            ->first();
        if (! $scene instanceof StoryScene) {
            throw StoryException::notFound('Scene');
        }

        return $scene;
    }

    /**
     * @param  array<string, mixed>  $params
     */
    private function version(Project $project, array $params): StorySceneVersion
    {
        $version = StorySceneVersion::query()
            ->where('uuid', (string) ($params['version_uuid'] ?? ''))
            ->whereHas('scene.reel.workspace', static fn ($workspace) => $workspace->where('project_id', $project->id))
            ->first();
        if (! $version instanceof StorySceneVersion) {
            throw StoryException::notFound('Scene version');
        }

        return $version;
    }
}
