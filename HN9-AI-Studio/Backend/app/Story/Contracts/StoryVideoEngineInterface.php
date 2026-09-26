<?php

declare(strict_types=1);

namespace App\Story\Contracts;

use App\Models\Project;
use App\Story\Enums\StoryVideoCapability;
use App\Story\Models\StoryVideoGenerationJob;
use App\Story\Video\StoryCapabilityRoute;
use App\Story\Video\StoryVideoCompatibilityResult;
use App\Story\Video\StoryVideoGenerationRequest;
use App\Story\Video\StoryVideoRoutingDecision;

interface StoryVideoEngineInterface
{
    /**
     * @return list<StoryCapabilityRoute>
     */
    public function catalog(): array;

    /**
     * @return list<array<string, mixed>>
     */
    public function providers(): array;

    public function route(StoryVideoCapability $capability): StoryCapabilityRoute;

    public function resolve(StoryVideoGenerationRequest $request): StoryVideoRoutingDecision;

    public function validateCompatibility(
        Project $project,
        StoryVideoGenerationRequest $request,
    ): StoryVideoCompatibilityResult;

    /**
     * Persist a queued engine job after routing. Does not call providers.
     *
     * @return array{job: StoryVideoGenerationJob, created: bool}
     */
    public function prepareJob(Project $project, StoryVideoGenerationRequest $request): array;

    /**
     * @param  array<string, mixed>  $input
     */
    public function execute(StoryVideoCapability $capability, array $input = []): never;
}
