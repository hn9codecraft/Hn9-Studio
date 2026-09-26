<?php

declare(strict_types=1);

namespace App\Story\Contracts;

use App\Story\Enums\StoryVideoCapability;
use App\Story\Video\StoryCapabilityRoute;
use App\Story\Video\StoryVideoGenerationRequest;
use App\Story\Video\StoryVideoRoutingDecision;

interface StoryCapabilityRouterInterface
{
    public function register(StoryVideoProviderAdapterInterface $adapter): void;

    /**
     * @return list<StoryVideoProviderAdapterInterface>
     */
    public function adapters(): array;

    /**
     * @return list<StoryVideoProviderAdapterInterface>
     */
    public function adaptersFor(StoryVideoCapability $capability): array;

    public function route(StoryVideoCapability $capability): StoryCapabilityRoute;

    /**
     * @return list<StoryCapabilityRoute>
     */
    public function catalog(): array;

    public function resolve(StoryVideoGenerationRequest $request): StoryVideoRoutingDecision;
}
