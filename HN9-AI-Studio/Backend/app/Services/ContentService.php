<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\Logging\ActivityLoggerInterface;
use App\Contracts\Services\ContentServiceInterface;
use App\DTOs\Content\ContentData;
use App\Models\GeneratedContent;
use App\Models\Project;
use App\Models\User;
use App\Repositories\Contracts\GeneratedContentRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;

/**
 * Business operations for generated textual content, including version
 * assignment. Manages content *records* only — text generation belongs to a
 * later sprint.
 */
final readonly class ContentService implements ContentServiceInterface
{
    public function __construct(
        private GeneratedContentRepositoryInterface $contents,
        private ActivityLoggerInterface $activity,
    ) {}

    public function paginateForUser(User $user, int $perPage = 15, array $filters = []): LengthAwarePaginator
    {
        // An administrator lists across every project; everyone else is scoped
        // to the projects they own. The policy grants viewAny to all, so this
        // scoping — not the policy — is what keeps a list private.
        return $this->contents->paginateForOwner(
            $user->isAdmin() ? null : $user->getKey(),
            $perPage,
            $filters,
            ['project'],
        );
    }

    public function forProject(Project $project): Collection
    {
        return $this->contents->forProject($project->getKey());
    }

    public function getByUuid(string $uuid): GeneratedContent
    {
        return $this->contents->findByUuidOrFail($uuid);
    }

    public function create(ContentData $data, ?User $causer = null): GeneratedContent
    {
        $attributes = $data->toArray();
        $attributes['version'] = $this->nextVersion($data->project_id, $data->type);

        $content = $this->contents->create($attributes);

        $this->activity->log('content.created', $content, $causer, 'Generated content recorded');

        return $content;
    }

    public function nextVersion(int $projectId, string $type): int
    {
        return $this->contents->latestVersion($projectId, $type) + 1;
    }

    public function update(GeneratedContent $content, array $data, ?User $causer = null): GeneratedContent
    {
        $updated = $this->contents->update($content, $data);

        $this->activity->log('content.updated', $updated, $causer, 'Generated content updated');

        return $updated;
    }

    public function setFavorite(GeneratedContent $content, bool $favorite, ?User $causer = null): GeneratedContent
    {
        $updated = $this->contents->update($content, ['is_favorite' => $favorite]);

        $this->activity->log(
            $favorite ? 'content.favorited' : 'content.unfavorited',
            $updated,
            $causer,
            $favorite ? 'Generated content favorited' : 'Generated content unfavorited',
        );

        return $updated;
    }

    public function delete(GeneratedContent $content, ?User $causer = null): bool
    {
        $deleted = $this->contents->delete($content);

        if ($deleted) {
            $this->activity->log('content.deleted', $content, $causer, 'Generated content deleted');
        }

        return $deleted;
    }
}
