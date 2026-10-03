<?php

declare(strict_types=1);

namespace App\Story\Contracts;

use App\Models\Project;
use App\Models\User;
use App\Story\Models\StoryProductionUnit;
use App\Story\Models\StoryVideoGenerationJob;
use Illuminate\Support\Collection;

/**
 * Generates one Generation Unit at a time through the existing video engine.
 * Provider choice and unit-version review are later modules.
 */
interface StoryProductionUnitGenerationServiceInterface
{
    /**
     * @param  array{capability: string, intent?: string|null, instruction?: string|null, aspect_ratio?: string|null, inputs?: list<array<string, mixed>>}  $input
     * @return array{job: StoryVideoGenerationJob, unit: StoryProductionUnit, created: bool}
     */
    public function generate(Project $project, string $planUuid, string $unitUuid, User $actor, array $input): array;

    public function refresh(Project $project, string $planUuid, string $unitUuid, string $jobUuid): StoryVideoGenerationJob;

    public function latest(Project $project, string $planUuid, string $unitUuid): ?StoryVideoGenerationJob;

    /**
     * @return Collection<int, StoryVideoGenerationJob>
     */
    public function attempts(Project $project, string $planUuid, string $unitUuid): Collection;

    public function cancel(Project $project, string $planUuid, string $unitUuid, string $jobUuid, User $actor): StoryVideoGenerationJob;
}
