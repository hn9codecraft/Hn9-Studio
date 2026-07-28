<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\Logging\ActivityLoggerInterface;
use App\Contracts\Services\GenerationRequestServiceInterface;
use App\DTOs\Generation\GenerationRequestData;
use App\Enums\ProjectStatus;
use App\Exceptions\GenerationException;
use App\Models\Project;
use App\Models\ProjectInput;
use App\Models\User;
use App\Repositories\Contracts\ProjectInputRepositoryInterface;
use App\Support\DomainHelper;

/**
 * Accepts requests to generate a deliverable and records them as project-input
 * briefs.
 *
 * IMPORTANT: this validates and PERSISTS the request only. It performs no
 * generation, dispatches no jobs and starts no workflow — the pipeline is a
 * later sprint.
 */
final readonly class GenerationRequestService implements GenerationRequestServiceInterface
{
    public function __construct(
        private ProjectInputRepositoryInterface $inputs,
        private ActivityLoggerInterface $activity,
    ) {}

    public function submit(Project $project, GenerationRequestData $data, ?User $causer = null): ProjectInput
    {
        $this->assertProjectAcceptsRequests($project);
        $this->assertSupportedLanguage($data->language);

        $attributes = $data->toArray();
        $attributes['project_id'] = $project->getKey();

        $input = $this->inputs->create($attributes);

        $this->activity->log('generation.requested', $input, $causer, 'Generation request recorded');

        return $input;
    }

    /**
     * A generation request may only target an editable project.
     */
    private function assertProjectAcceptsRequests(Project $project): void
    {
        $status = ProjectStatus::tryFrom((string) $project->status) ?? ProjectStatus::Draft;

        if (! $status->isEditable()) {
            throw GenerationException::projectNotEditable($project->uuid);
        }
    }

    private function assertSupportedLanguage(string $language): void
    {
        if (! DomainHelper::isSupportedLocale($language)) {
            throw new GenerationException(
                message: "Unsupported content language [{$language}].",
                errorCode: 'generation_unsupported_language',
                statusCode: 422,
                context: ['language' => $language],
            );
        }
    }
}
