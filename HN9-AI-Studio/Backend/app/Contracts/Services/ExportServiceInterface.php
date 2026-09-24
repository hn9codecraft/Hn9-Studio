<?php

declare(strict_types=1);

namespace App\Contracts\Services;

use App\Models\Export;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\StreamedResponse;

interface ExportServiceInterface
{
    /**
     * @return array<string, mixed>
     */
    public function readiness(Project $project): array;

    public function finalize(Project $project, User $actor): Project;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function create(User $user, array $payload): Export;

    public function createForProject(Project $project, User $actor): Export;

    /**
     * @return Collection<int, Export>
     */
    public function index(User $user): Collection;

    /**
     * @return Collection<int, Export>
     */
    public function listForProject(Project $project): Collection;

    public function show(User $user, string $uuid): ?Export;

    public function findForProject(Project $project, string $uuid): Export;

    public function download(User $user, string $uuid): StreamedResponse;

    public function downloadForProject(Project $project, string $uuid): StreamedResponse;

    public function build(Export $export): Export;
}
