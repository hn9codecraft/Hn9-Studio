<?php

declare(strict_types=1);

namespace App\Story\Video;

use App\Story\Contracts\StoryCapabilityRouterInterface;
use App\Story\Contracts\StoryVideoEngineInterface;
use App\Story\Enums\StoryVideoCapability;
use App\Story\Exceptions\StoryGenerationNotAvailableException;

/**
 * @deprecated Prefer StoryVideoEngine. Kept for backward-compatible unit tests.
 */
final readonly class UnavailableStoryVideoEngine implements StoryVideoEngineInterface
{
    public function __construct(private StoryCapabilityRouterInterface $router) {}

    public function catalog(): array
    {
        return $this->router->catalog();
    }

    public function providers(): array
    {
        return [];
    }

    public function route(StoryVideoCapability $capability): StoryCapabilityRoute
    {
        return $this->router->route($capability);
    }

    public function resolve(\App\Story\Video\StoryVideoGenerationRequest $request): StoryVideoRoutingDecision
    {
        return $this->router->resolve($request);
    }

    public function validateCompatibility(
        \App\Models\Project $project,
        StoryVideoGenerationRequest $request,
    ): StoryVideoCompatibilityResult {
        $routing = $this->router->resolve($request);

        return new StoryVideoCompatibilityResult(
            capability: $request->capability,
            compatible: $routing->matched,
            issues: $routing->matched ? [] : ['no_eligible_provider'],
            routing: $routing,
        );
    }

    public function prepareJob(\App\Models\Project $project, StoryVideoGenerationRequest $request): array
    {
        throw StoryGenerationNotAvailableException::make();
    }

    public function execute(StoryVideoCapability $capability, array $input = []): never
    {
        throw StoryGenerationNotAvailableException::make();
    }
}
