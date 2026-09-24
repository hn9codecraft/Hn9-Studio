<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\Logging\ActivityLoggerInterface;
use App\Contracts\Services\ScriptReviewServiceInterface;
use App\Contracts\Services\ScriptServiceInterface;
use App\DTOs\Script\CreateScriptData;
use App\DTOs\Script\UpdateScriptData;
use App\Enums\ScriptStatus;
use App\Exceptions\ScriptWorkflowException;
use App\Models\Project;
use App\Models\Script;
use App\Models\User;
use App\Repositories\Contracts\ScriptRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Business rules for studio scripts. Persistence is delegated to the repository.
 */
final readonly class ScriptService implements ScriptServiceInterface
{
    /**
     * @var list<string>
     */
    private const SCRIPT_RELATIONS = [
        'project',
        'generatedContent',
        'parentScript',
        'latestReviewEvent.user',
        'latestReworkEvent.user',
    ];

    public function __construct(
        private ScriptRepositoryInterface $scripts,
        private ActivityLoggerInterface $activity,
        private ScriptReviewServiceInterface $reviews,
    ) {}

    public function paginateForProject(Project $project, int $perPage = 15, array $filters = []): LengthAwarePaginator
    {
        return $this->scripts->paginateForProject($project->getKey(), $perPage, $filters, self::SCRIPT_RELATIONS);
    }

    public function getByUuid(string $uuid): Script
    {
        return $this->scripts->findByUuidOrFail($uuid, self::SCRIPT_RELATIONS);
    }

    public function create(CreateScriptData $data, ?User $causer = null): Script
    {
        $attributes = $data->toArray();

        if (isset($attributes['status']) && ! in_array($attributes['status'], ScriptStatus::assignableValues(), true)) {
            throw ScriptWorkflowException::statusNotAssignable('new', (string) $attributes['status']);
        }

        $script = $this->scripts->create($attributes);
        $script->load(self::SCRIPT_RELATIONS);

        $this->activity->log('script.created', $script, $causer, 'Script created');

        return $script;
    }

    public function update(Script $script, UpdateScriptData $data, ?User $causer = null): Script
    {
        $payload = $data->toArray();
        $current = $script->statusEnum();

        if (array_key_exists('status', $payload)) {
            $target = (string) $payload['status'];

            if (! in_array($target, ScriptStatus::assignableValues(), true)) {
                throw ScriptWorkflowException::statusNotAssignable($script->uuid, $target);
            }

            if (! in_array($current, [ScriptStatus::Draft, ScriptStatus::Ready, ScriptStatus::Archived], true)) {
                throw ScriptWorkflowException::statusNotAssignable($script->uuid, $target);
            }
        }

        $touchesContent = array_key_exists('title', $payload) || array_key_exists('body', $payload);

        if ($touchesContent && ! $current->allowsContentEdit()) {
            throw ScriptWorkflowException::editLocked($script->uuid, $current->value);
        }

        $wasNeedsRework = $current === ScriptStatus::NeedsRework
            && $touchesContent
            && $this->contentChanged($script, $payload);

        $script = $this->scripts->update($script, $payload);
        $script->load(self::SCRIPT_RELATIONS);

        $this->activity->log('script.updated', $script, $causer, 'Script updated');

        if ($wasNeedsRework && $causer !== null) {
            $this->reviews->recordReworked($script, $causer);
            $script->load(self::SCRIPT_RELATIONS);
        }

        return $script;
    }

    public function delete(Script $script, ?User $causer = null): bool
    {
        $deleted = $this->scripts->delete($script);

        if ($deleted) {
            $this->activity->log('script.deleted', $script, $causer, 'Script deleted');
        }

        return $deleted;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function contentChanged(Script $script, array $payload): bool
    {
        if (array_key_exists('title', $payload) && (string) $payload['title'] !== (string) $script->title) {
            return true;
        }

        if (array_key_exists('body', $payload) && (string) $payload['body'] !== (string) $script->body) {
            return true;
        }

        return false;
    }
}
