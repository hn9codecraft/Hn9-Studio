<?php

declare(strict_types=1);

namespace App\Story\Contracts;

use App\Models\Project;
use App\Models\User;
use Symfony\Component\HttpFoundation\StreamedResponse;

interface StorySceneAssemblyServiceInterface
{
    /**
     * Build the scene from the selected unit versions, or reuse that build.
     *
     * @return array{created: bool, assembly: array<string, mixed>}
     */
    public function assemble(Project $project, string $planUuid, string $sceneUuid, User $actor): array;

    /**
     * @return array{current: array<string, mixed>|null, assemblies: list<array<string, mixed>>}
     */
    public function listForScene(Project $project, string $planUuid, string $sceneUuid): array;

    /**
     * One read for the scene workspace: backend clips, their versions and scene videos.
     * Provider names, storage paths and operation ids are left out.
     *
     * @return array<string, mixed>
     */
    public function workspace(Project $project, string $planUuid, string $sceneUuid): array;

    /**
     * @return array<string, mixed>
     */
    public function show(Project $project, string $planUuid, string $sceneUuid, string $assemblyUuid): array;

    public function file(Project $project, string $planUuid, string $sceneUuid, string $assemblyUuid): StreamedResponse;

    /**
     * The newest completed scene video, for a later timeline. Null when none is ready.
     *
     * @return array<string, mixed>|null
     */
    public function current(Project $project, string $planUuid, string $sceneUuid): ?array;

    public function execute(int $assemblyId): void;

    public function recoverStale(): int;
}
