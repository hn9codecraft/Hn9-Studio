<?php

declare(strict_types=1);

namespace App\Story\Video;

use App\Story\Contracts\StoryVideoProviderAdapterInterface;
use App\Story\Enums\StoryVideoAsyncMode;
use App\Story\Enums\StoryVideoCapability;
use App\Story\Enums\StoryVideoJobStatus;
use App\Story\Exceptions\StoryVideoEngineException;
use App\Story\Models\StoryVideoGenerationJob;
use App\Story\Video\StoryVideoSubmission;

/**
 * Generic catalog adapter. Keys are configuration-supplied (catalog.alpha, etc.)
 * — never vendor names from core business code. Never opens a network socket.
 */
final readonly class CatalogStoryVideoAdapter implements StoryVideoProviderAdapterInterface
{
    /**
     * @param  list<StoryVideoCapability>  $capabilities
     * @param  list<int>  $durations
     * @param  list<string>  $aspectRatios
     * @param  list<string>  $resolutions
     * @param  list<string>  $inputTypes
     * @param  list<StoryVideoModelSpec>  $models
     */
    public function __construct(
        private string $adapterKey,
        private string $label = 'Catalog Provider',
        private array $capabilities = [],
        private bool $enabled = true,
        private bool $available = false,
        private int $priority = 100,
        private array $durations = [],
        private ?int $minDuration = null,
        private ?int $maxDuration = null,
        private array $aspectRatios = [],
        private array $resolutions = [],
        private array $inputTypes = [],
        private bool $audio = false,
        private StoryVideoAsyncMode $mode = StoryVideoAsyncMode::AsyncPoll,
        private bool $polling = true,
        private bool $webhook = false,
        private bool $download = true,
        private array $models = [],
    ) {}

    public function key(): string
    {
        return $this->adapterKey;
    }

    public function displayName(): string
    {
        return $this->label;
    }

    public function enabled(): bool
    {
        return $this->enabled;
    }

    public function priority(): int
    {
        return $this->priority;
    }

    public function supports(StoryVideoCapability $capability): bool
    {
        return in_array($capability, $this->capabilities, true);
    }

    public function isAvailable(StoryVideoCapability $capability): bool
    {
        return $this->enabled && $this->available && $this->supports($capability);
    }

    public function supportedDurations(StoryVideoCapability $capability): array
    {
        return $this->supports($capability) ? $this->durations : [];
    }

    public function minDurationSeconds(StoryVideoCapability $capability): ?int
    {
        return $this->supports($capability) ? $this->minDuration : null;
    }

    public function maxDurationSeconds(StoryVideoCapability $capability): ?int
    {
        return $this->supports($capability) ? $this->maxDuration : null;
    }

    public function supportedAspectRatios(StoryVideoCapability $capability): array
    {
        return $this->supports($capability) ? $this->aspectRatios : [];
    }

    public function supportedResolutions(StoryVideoCapability $capability): array
    {
        return $this->supports($capability) ? $this->resolutions : [];
    }

    public function supportedInputTypes(StoryVideoCapability $capability): array
    {
        return $this->supports($capability) ? $this->inputTypes : [];
    }

    public function audioSupported(StoryVideoCapability $capability): bool
    {
        return $this->supports($capability) && $this->audio;
    }

    public function asyncMode(StoryVideoCapability $capability): StoryVideoAsyncMode
    {
        return $this->mode;
    }

    public function supportsPolling(StoryVideoCapability $capability): bool
    {
        return $this->supports($capability) && $this->polling;
    }

    public function supportsWebhook(StoryVideoCapability $capability): bool
    {
        return $this->supports($capability) && $this->webhook;
    }

    public function supportsDownload(StoryVideoCapability $capability): bool
    {
        return $this->supports($capability) && $this->download;
    }

    public function models(): array
    {
        return $this->models;
    }

    public function validate(StoryVideoGenerationRequest $request): void
    {
        if (! $this->supports($request->capability)) {
            throw StoryVideoEngineException::capabilityNotAvailable(
                'Adapter does not support capability '.$request->capability->value.'.',
            );
        }

        if ($request->durationSeconds !== null) {
            $durations = $this->supportedDurations($request->capability);
            $min = $this->minDurationSeconds($request->capability);
            $max = $this->maxDurationSeconds($request->capability);
            $seconds = $request->durationSeconds;

            $ok = ($durations !== [] && in_array($seconds, $durations, true))
                || ($durations === [] && ($min === null || $seconds >= $min) && ($max === null || $seconds <= $max));

            if (! $ok) {
                throw StoryVideoEngineException::invalidInput('Duration is not supported by the selected adapter.');
            }
        }

        if ($request->aspectRatio !== null) {
            $ratios = $this->supportedAspectRatios($request->capability);
            if ($ratios !== [] && ! in_array($request->aspectRatio, $ratios, true)) {
                throw StoryVideoEngineException::invalidInput('Aspect ratio is not supported by the selected adapter.');
            }
        }

        if ($request->audioRequested && ! $this->audioSupported($request->capability)) {
            throw StoryVideoEngineException::invalidInput('Audio is not supported by the selected adapter.');
        }
    }

    public function submit(StoryVideoGenerationRequest $request): StoryVideoSubmission
    {
        throw StoryVideoEngineException::generationNotEnabled();
    }

    public function status(StoryVideoGenerationJob $job): StoryVideoJobStatus
    {
        return StoryVideoJobStatus::tryFrom((string) $job->status) ?? StoryVideoJobStatus::Queued;
    }

    public function cancel(StoryVideoGenerationJob $job): void
    {
        // Catalog adapters do not talk to providers.
    }

    public function result(StoryVideoGenerationJob $job): StoryVideoGenerationOutput
    {
        throw StoryVideoEngineException::generationNotEnabled();
    }
}
