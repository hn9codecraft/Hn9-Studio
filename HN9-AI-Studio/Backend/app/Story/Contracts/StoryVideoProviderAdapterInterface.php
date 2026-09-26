<?php

declare(strict_types=1);

namespace App\Story\Contracts;

use App\Story\Enums\StoryVideoAsyncMode;
use App\Story\Enums\StoryVideoCapability;
use App\Story\Enums\StoryVideoJobStatus;
use App\Story\Models\StoryVideoGenerationJob;
use App\Story\Video\StoryVideoGenerationOutput;
use App\Story\Video\StoryVideoGenerationRequest;
use App\Story\Video\StoryVideoModelSpec;
use App\Story\Video\StoryVideoSubmission;

/**
 * Story video provider adapter boundary. Vendor specifics stay inside adapters.
 * M11.6 adapters are catalog-only and must not open provider sockets.
 */
interface StoryVideoProviderAdapterInterface
{
    public function key(): string;

    public function displayName(): string;

    public function enabled(): bool;

    public function priority(): int;

    public function supports(StoryVideoCapability $capability): bool;

    public function isAvailable(StoryVideoCapability $capability): bool;

    /**
     * @return list<int>
     */
    public function supportedDurations(StoryVideoCapability $capability): array;

    public function minDurationSeconds(StoryVideoCapability $capability): ?int;

    public function maxDurationSeconds(StoryVideoCapability $capability): ?int;

    /**
     * @return list<string>
     */
    public function supportedAspectRatios(StoryVideoCapability $capability): array;

    /**
     * @return list<string>
     */
    public function supportedResolutions(StoryVideoCapability $capability): array;

    /**
     * @return list<string>
     */
    public function supportedInputTypes(StoryVideoCapability $capability): array;

    public function audioSupported(StoryVideoCapability $capability): bool;

    /**
     * Audio roles this adapter may generate for the AUDIO capability.
     * Empty means the adapter does not accept role-filtered AUDIO requests.
     *
     * @return list<string>
     */
    public function supportedAudioRoles(StoryVideoCapability $capability): array;

    public function asyncMode(StoryVideoCapability $capability): StoryVideoAsyncMode;

    public function supportsPolling(StoryVideoCapability $capability): bool;

    public function supportsWebhook(StoryVideoCapability $capability): bool;

    public function supportsDownload(StoryVideoCapability $capability): bool;

    /**
     * @return list<StoryVideoModelSpec>
     */
    public function models(): array;

    public function validate(StoryVideoGenerationRequest $request): void;

    /**
     * Provider submission. Catalog adapters must not perform network I/O.
     * Real adapters return a normalized operation. Vendor payloads stay inside the adapter.
     */
    public function submit(StoryVideoGenerationRequest $request): StoryVideoSubmission;

    public function status(StoryVideoGenerationJob $job): StoryVideoJobStatus;

    public function cancel(StoryVideoGenerationJob $job): void;

    public function result(StoryVideoGenerationJob $job): StoryVideoGenerationOutput;
}
