<?php

declare(strict_types=1);

namespace App\Story\Contracts;

use App\Models\Project;
use App\Models\User;
use App\Story\Models\StoryProductionUnit;
use App\Story\Models\StoryProductionUnitVersion;
use App\Story\Models\StoryVideoGenerationJob;
use Symfony\Component\HttpFoundation\StreamedResponse;

interface StoryProductionUnitVersionServiceInterface
{
    /**
     * Turns one accepted generation into the next version of its unit.
     * The same job never creates a second version.
     */
    public function recordAccepted(
        StoryVideoGenerationJob $job,
        StoryProductionUnit $unit,
        float $producedSeconds,
        string $disk,
        string $path,
        string $mime,
    ): StoryProductionUnitVersion;

    /**
     * @return array{message: string|null, versions: list<array<string, mixed>>}
     */
    public function listForUnit(Project $project, string $planUuid, string $unitUuid): array;

    /**
     * @return array<string, mixed>
     */
    public function show(Project $project, string $planUuid, string $unitUuid, string $versionUuid): array;

    /**
     * @return array{version: array<string, mixed>, changed: bool}
     */
    public function approve(Project $project, string $planUuid, string $unitUuid, string $versionUuid, User $actor, ?string $comment = null): array;

    /**
     * @return array{version: array<string, mixed>, changed: bool}
     */
    public function requestChanges(Project $project, string $planUuid, string $unitUuid, string $versionUuid, User $actor, string $comment): array;

    /**
     * @return array{version: array<string, mixed>, changed: bool}
     */
    public function select(Project $project, string $planUuid, string $unitUuid, string $versionUuid, User $actor): array;

    public function file(Project $project, string $planUuid, string $unitUuid, string $versionUuid): StreamedResponse;

    /**
     * The selected approved output for a later scene assembly. Null until one is chosen.
     *
     * @return array<string, mixed>|null
     */
    public function assemblySource(StoryProductionUnit $unit): ?array;

    /**
     * Continuity for the next unit. An unselected version is not returned.
     *
     * @return array<string, mixed>
     */
    public function continuityOutput(StoryProductionUnit $unit): array;
}
