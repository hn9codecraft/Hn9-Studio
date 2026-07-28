<?php

declare(strict_types=1);

namespace App\Contracts\Services;

use App\DTOs\Content\ContentData;
use App\Models\GeneratedContent;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

/**
 * Business operations for generated textual content (record management only —
 * no text is produced in this sprint).
 */
interface ContentServiceInterface
{
    /**
     * All content for a project.
     *
     * @return Collection<int, GeneratedContent>
     */
    public function forProject(Project $project): Collection;

    public function getByUuid(string $uuid): GeneratedContent;

    public function create(ContentData $data, ?User $causer = null): GeneratedContent;

    /**
     * The next version number to use for a project/type pair.
     */
    public function nextVersion(int $projectId, string $type): int;

    public function delete(GeneratedContent $content, ?User $causer = null): bool;
}
